<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\WorkspaceData;
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
    public function addWorktree(
        ProjectData $projectData,
        WorkspaceData $workspaceData,
        ?string $baseBranch,
    ): void {
        if (File::exists($workspaceData->path)) {
            throw new WorkspaceDirectoryExists($workspaceData->path);
        }

        if ($this->doesBranchExist($projectData->path, $workspaceData->branch) && ! $baseBranch) {
            $command = 'git worktree add "'.$projectData->parentDir().DIRECTORY_SEPARATOR.$workspaceData->slugKebab().'" "'.$workspaceData->branch.'"';
        } elseif ($baseBranch && $this->doesBranchExist($projectData->path, $baseBranch)) {
            $command = 'git worktree add -b "'.$workspaceData->branch.'" "'.$projectData->parentDir().DIRECTORY_SEPARATOR.$workspaceData->slugKebab().'" "'.$baseBranch.'"';
        } elseif ($baseBranch) {
            throw new GitBranchDoesNotExist($projectData->path, $baseBranch);
        } else {
            throw new RuntimeException('Branch must exist or base branch must be provided; mutually exclusive');
        }

        $process = Process::fromShellCommandline($command, $projectData->path);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('add worktree '.($baseBranch ? '(new branch)' : '(existing branch)'), $process->getErrorOutput());
        }
    }

    public function removeWorktree(
        ProjectData $projectData,
        WorkspaceData $workspaceData,
        bool $force,
        bool $deleteBranch,
        bool $forceDeleteBranch,
    ): void {
        $removeWorktreeCommand = $force
            ? 'git worktree remove --force '.$workspaceData->path
            : 'git worktree remove '.$workspaceData->path;
        $removeWorktreeProcess = Process::fromShellCommandline($removeWorktreeCommand, $workspaceData->path);
        $removeWorktreeProcess->run();

        if (! $removeWorktreeProcess->isSuccessful()) {
            throw new GitOperationFailed('remove worktree'.($force ? ' (forced)' : ''), $removeWorktreeProcess->getErrorOutput());
        }

        $deleteBranchCommand = match (true) {
            $deleteBranch && $forceDeleteBranch => 'git branch --delete --force '.$workspaceData->branch,
            $deleteBranch => 'git branch --delete '.$workspaceData->branch,
            default => null,
        };

        if ($deleteBranchCommand) {
            $deleteBranchProcess = Process::fromShellCommandline($removeWorktreeCommand, $workspaceData->path);
            $deleteBranchProcess->run();

            if (! $deleteBranchProcess->isSuccessful()) {
                throw new GitOperationFailed('delete branch'.($force ? ' (forced)' : ''), $deleteBranchProcess->getErrorOutput());
            }
        }
    }

    /**
     * @return Collection<int, WorktreeData>
     */
    public function listWorktrees(string $projectPath): Collection
    {
        $output = Process::fromShellCommandline('git worktree list --porcelain', $projectPath)
            ->mustRun()
            ->getOutput();

        return collect(explode("\n\n", trim($output)))
            ->map(fn (string $record, int $key) => $this->parseRecord($record, $key))
            ->filter()
            ->values();
    }

    /**
     * Bare worktrees have no working tree to open, so they are excluded.
     */
    private function parseRecord(string $record, int $key): ?WorktreeData
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
    private function parseFields(string $record): array
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
        $process = Process::fromShellCommandline('git show-ref --verify --quiet "refs/heads/'.$branch.'"', $projectPath);
        $process->run();

        return $process->isSuccessful();
    }
}
