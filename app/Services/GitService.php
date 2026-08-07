<?php

namespace App\Services;

use App\Data\GitStatusEntryData;
use App\Data\WorktreeData;
use App\Exceptions\GitBranchDoesNotExist;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\WorkspaceDirectoryExists;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

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
        if (File::exists($newWorktreePath)) {
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

        $process = $this->gitProcess($command, $mainWorktreePath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('add worktree '.($baseBranch ? '(new branch)' : '(existing branch)'), $process->getErrorOutput());
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
        $removeWorktreeProcess = $this->gitProcess($removeWorktreeCommand, $worktreePath);
        $removeWorktreeProcess->run();

        if (! $removeWorktreeProcess->isSuccessful()) {
            throw new GitOperationFailed('remove worktree'.($force ? ' (forced)' : ''), $removeWorktreeProcess->getErrorOutput());
        }

        $deleteBranchCommand = match (true) {
            $deleteBranch && $forceDeleteBranch => 'git branch --delete --force '.$branch,
            $deleteBranch => 'git branch --delete '.$branch,
            default => null,
        };

        if ($deleteBranchCommand) {
            $deleteBranchProcess = $this->gitProcess($deleteBranchCommand, $mainWorktreePath);
            $deleteBranchProcess->run();

            if (! $deleteBranchProcess->isSuccessful()) {
                throw new GitOperationFailed('delete branch'.($force ? ' (forced)' : ''), $deleteBranchProcess->getErrorOutput());
            }
        }
    }

    /**
     * @return Collection<int, WorktreeData>
     *
     * @throws GitOperationFailed
     */
    public function listWorktrees(string $projectPath): Collection
    {
        $process = $this->gitProcess('git worktree list --porcelain', $projectPath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('list worktrees', $process->getErrorOutput());
        }

        return collect(explode("\n\n", trim($process->getOutput())))
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
        $process = $this->gitProcess("git for-each-ref --format='%(refname:short)' refs/heads", $projectPath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('list local branches', $process->getErrorOutput());
        }

        return collect(explode("\n", trim($process->getOutput())))
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
        $process = $this->gitProcess('git status --porcelain', $projectPath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('get status', $process->getErrorOutput());
        }

        return collect(explode("\n", rtrim($process->getOutput(), "\n")))
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
        $stageProcess = $this->gitProcess('git add --all', $projectPath);
        $stageProcess->run();

        if (! $stageProcess->isSuccessful()) {
            throw new GitOperationFailed('stage all changes', $stageProcess->getErrorOutput());
        }

        $commitProcess = $this->gitProcess('git commit --message '.escapeshellarg($message), $projectPath);
        $commitProcess->run();

        if (! $commitProcess->isSuccessful()) {
            throw new GitOperationFailed('commit changes', $commitProcess->getErrorOutput() ?: $commitProcess->getOutput());
        }
    }

    /**
     * @throws GitOperationFailed
     */
    public function currentBranch(string $projectPath): string
    {
        $process = $this->gitProcess('git rev-parse --abbrev-ref HEAD', $projectPath);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('get current branch', $process->getErrorOutput());
        }

        return trim($process->getOutput());
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
        $process = $this->gitProcess('git show-ref --verify --quiet "refs/heads/'.$branch.'"', $projectPath);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Build a git process that runs without this application's own environment.
     */
    protected function gitProcess(string $command, string $cwd): Process
    {
        return Process::fromShellCommandline(
            command: $command,
            cwd: $cwd,
            env: app(ProcessEnvironmentService::class)->sanitized(),
        );
    }
}
