<?php

namespace App\Services;

use App\Data\GitStatusEntryData;
use App\Data\WorktreeData;
use App\Enums\Directory;
use App\Enums\File;
use App\Exceptions\GitBranchDoesNotExist;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\WorkspaceDirectoryExists;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class GitService
{
    /**
     * @throws WorkspaceDirectoryExists
     * @throws GitBranchDoesNotExist
     * @throws RuntimeException
     * @throws GitOperationFailed
     */
    public function addWorktree(
        string $mainWorktreePath,
        string $newWorktreePath,
        string $branch,
        ?string $baseBranch,
    ): WorktreeData {
        if (\Illuminate\Support\Facades\File::exists($newWorktreePath)) {
            throw new WorkspaceDirectoryExists($newWorktreePath);
        }

        if ($this->doesBranchExist($mainWorktreePath, $branch) && ! $baseBranch) {
            $command = 'git worktree add "'.$newWorktreePath.'" "'.$branch.'"';
        } elseif ($baseBranch && $this->doesBranchExist($mainWorktreePath, $baseBranch)) {
            $command = 'git worktree add -b "'.$branch.'" "'.$newWorktreePath.'" "'.$baseBranch.'"';
        } elseif ($baseBranch) {
            throw new GitBranchDoesNotExist($mainWorktreePath, $baseBranch);
        } else {
            throw new RuntimeException('Branch must exist or base branch must be provided; mutually exclusive');
        }

        $result = $this->runGit($command, $mainWorktreePath);

        if ($result->failed()) {
            throw new GitOperationFailed('add worktree '.($baseBranch ? '(new branch)' : '(existing branch)'), $result->errorOutput());
        }

        return new WorktreeData(
            is_primary: false,
            path: $newWorktreePath,
            branch: $branch,
        );
    }

    /**
     * @throws GitOperationFailed
     */
    public function removeWorktree(
        string $mainWorktreePath,
        string $worktreePath,
        string $branch,
        bool $force,
        bool $deleteBranch,
        bool $forceDeleteBranch,
    ): void {
        $removeWorktreeCommand = $force
            ? 'git worktree remove --force '.$worktreePath
            : 'git worktree remove '.$worktreePath;
        $removeWorktreeResult = $this->runGit($removeWorktreeCommand, $worktreePath);

        if ($removeWorktreeResult->failed()) {
            throw new GitOperationFailed('remove worktree'.($force ? ' (forced)' : ''), $removeWorktreeResult->errorOutput());
        }

        $deleteBranchCommand = match (true) {
            $deleteBranch && $forceDeleteBranch => 'git branch --delete --force '.$branch,
            $deleteBranch => 'git branch --delete '.$branch,
            default => null,
        };

        if ($deleteBranchCommand) {
            $deleteBranchResult = $this->runGit($deleteBranchCommand, $mainWorktreePath);

            if ($deleteBranchResult->failed()) {
                throw new GitOperationFailed('delete branch'.($force ? ' (forced)' : ''), $deleteBranchResult->errorOutput());
            }
        }
    }

    /**
     * Remove every linked worktree of a repository, leaving the primary worktree and all branches
     * in place. Aborts on the first failure, so an interrupted run leaves the repository usable.
     *
     * @throws GitOperationFailed
     */
    public function removeLinkedWorktrees(string $mainWorktreePath, bool $force): void
    {
        $this->listWorktrees($mainWorktreePath)
            ->reject(fn (WorktreeData $worktreeData) => $worktreeData->is_primary)
            ->each(fn (WorktreeData $worktreeData) => $this->removeWorktree(
                mainWorktreePath: $mainWorktreePath,
                worktreePath: $worktreeData->path,
                branch: $worktreeData->branch ?? '',
                force: $force,
                deleteBranch: false,
                forceDeleteBranch: false,
            ));
    }

    /**
     * @return Collection<int, WorktreeData>
     *
     * @throws GitOperationFailed
     */
    public function listWorktrees(string $projectPath): Collection
    {
        $result = $this->runGit('git worktree list --porcelain', $projectPath);

        if ($result->failed()) {
            throw new GitOperationFailed('list worktrees', $result->errorOutput());
        }

        return collect(explode("\n\n", trim($result->output())))
            ->map(fn (string $record, int $key) => $this->parseRecord($record, $key))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, string>
     *
     * @throws GitOperationFailed
     */
    public function listLocalBranches(string $projectPath): Collection
    {
        $result = $this->runGit("git for-each-ref --format='%(refname:short)' refs/heads", $projectPath);

        if ($result->failed()) {
            throw new GitOperationFailed('list local branches', $result->errorOutput());
        }

        return collect(explode("\n", trim($result->output())))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, GitStatusEntryData>
     *
     * @throws GitOperationFailed
     */
    public function status(string $projectPath): Collection
    {
        $result = $this->runGit('git status --porcelain', $projectPath);

        if ($result->failed()) {
            throw new GitOperationFailed('get status', $result->errorOutput());
        }

        return collect(explode("\n", rtrim($result->output(), "\n")))
            ->filter(fn (string $line) => strlen($line) > 3)
            ->map(fn (string $line) => new GitStatusEntryData(
                code: substr($line, 0, 2),
                path: trim(substr($line, 3)),
            ))
            ->values();
    }

    /**
     * @throws GitOperationFailed
     */
    public function isStatusClean(string $projectPath): bool
    {
        return $this->status($projectPath)->isEmpty();
    }

    /**
     * @throws GitOperationFailed
     */
    public function commitAll(string $projectPath, string $message): void
    {
        $stageResult = $this->runGit('git add --all', $projectPath);

        if ($stageResult->failed()) {
            throw new GitOperationFailed('stage all changes', $stageResult->errorOutput());
        }

        $commitResult = $this->runGit('git commit --message '.escapeshellarg($message), $projectPath);

        if ($commitResult->failed()) {
            throw new GitOperationFailed('commit changes', $commitResult->errorOutput() ?: $commitResult->output());
        }
    }

    /**
     * Append an ignore entry to the repository's own exclude file, which keeps the entry local to
     * this clone instead of committing it. Doing so twice is a no-op.
     *
     * @throws GitOperationFailed
     */
    public function addToGitInfoExclude(string $projectPath, string $entry): void
    {
        $excludePath = $this->gitCommonDirectory($projectPath)
            .DIRECTORY_SEPARATOR.Directory::GIT_INFO->value
            .DIRECTORY_SEPARATOR.File::GIT_EXCLUDE->value;

        $contents = \Illuminate\Support\Facades\File::isFile($excludePath)
            ? \Illuminate\Support\Facades\File::get($excludePath)
            : '';

        $entry = trim($entry);

        if (collect(explode("\n", $contents))->map(trim(...))->contains($entry)) {
            return;
        }

        $separator = ($contents === '' || str_ends_with($contents, "\n")) ? '' : PHP_EOL;

        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($excludePath));
        \Illuminate\Support\Facades\File::append($excludePath, $separator.$entry.PHP_EOL);
    }

    /**
     * @throws GitOperationFailed
     */
    public function currentBranch(string $projectPath): string
    {
        $result = $this->runGit('git rev-parse --abbrev-ref HEAD', $projectPath);

        if ($result->failed()) {
            throw new GitOperationFailed('get current branch', $result->errorOutput());
        }

        return trim($result->output());
    }

    /**
     * Bare worktrees have no working tree to open, so they are excluded.
     */
    protected function parseRecord(string $record, int $key): ?WorktreeData
    {
        $fields = $this->parseFields($record);

        if (array_key_exists('bare', $fields)) {
            return null;
        }

        return new WorktreeData(
            is_primary: $key === 0,
            path: $fields['worktree'],
            branch: Str::after($fields['branch'] ?? '', 'refs/heads/') ?: null,
            sha: $fields['HEAD'] ?? null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    protected function parseFields(string $record): array
    {
        return collect(explode("\n", trim($record)))
            ->filter()
            ->mapWithKeys(function (string $line) {
                [$key, $value] = array_pad(explode(' ', $line, 2), 2, null);

                return [$key => $value];
            })
            ->all();
    }

    public function doesBranchExist(string $projectPath, string $branch): bool
    {
        return $this->runGit('git show-ref --verify --quiet "refs/heads/'.$branch.'"', $projectPath)->successful();
    }

    public function isGitRepository(string $path): bool
    {
        return $this->runGit('git rev-parse --git-common-dir', $path)->successful();
    }

    /**
     * Resolve the repository's common git directory, which is not always a `.git` directory inside
     * the given path: linked worktrees and submodules point elsewhere, and git reports that path
     * as absolute while an ordinary checkout reports it relative to the working directory.
     *
     * @throws GitOperationFailed
     */
    protected function gitCommonDirectory(string $projectPath): string
    {
        $result = $this->runGit('git rev-parse --git-common-dir', $projectPath);

        if ($result->failed()) {
            throw new GitOperationFailed('locate the git directory', $result->errorOutput());
        }

        $gitDirectory = trim($result->output());

        return str_starts_with($gitDirectory, DIRECTORY_SEPARATOR)
            ? $gitDirectory
            : $projectPath.DIRECTORY_SEPARATOR.$gitDirectory;
    }

    /**
     * Run a git command in the given directory without this application's own environment.
     *
     * Private rather than protected: this is not a test seam. Process::fake() intercepts below it,
     * so a test doubles the process itself rather than the way one is built.
     */
    private function runGit(string $command, string $cwd): ProcessResult
    {
        return Process::path($cwd)
            ->env(app(ProcessEnvironmentService::class)->sanitized())
            ->run($command);
    }
}
