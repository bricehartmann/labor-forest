<?php

namespace App\Services;

use App\Data\WorktreeData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class GitWorktreeService
{
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
