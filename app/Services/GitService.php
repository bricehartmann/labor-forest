<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Data\WorktreeData;
use App\Exceptions\GitOperationFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class GitService
{
    public function removeWorktree(
        ProjectData $projectData,
        WorkspaceData $workspaceData,
        bool $force,
        bool $deleteBranch,
        bool $forceDeleteBranch,
    ): void {
        if ($force) {
            $process = Process::fromShellCommandline('git worktree remove --force '.$workspaceData->path, $workspaceData->path);
        } else {
            $process = Process::fromShellCommandline('git worktree remove '.$workspaceData->path, $workspaceData->path);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('remove worktree', $process->getErrorOutput());
        }

        if ($deleteBranch && $forceDeleteBranch) {
            $process = Process::fromShellCommandline('git branch --delete --force '.$workspaceData->branch, $projectData->path);
        } else {
            $process = Process::fromShellCommandline('git branch --delete '.$workspaceData->branch, $projectData->path);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitOperationFailed('delete branch', $process->getErrorOutput());
        }
    }

    /**
     * @return Collection<int, WorktreeData>
     */
    public function listWorktrees(string $path): Collection
    {
        $output = Process::fromShellCommandline('git worktree list --porcelain', $path)
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
}
