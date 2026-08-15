<?php

use App\Data\GitStatusEntryData;
use App\Data\WorktreeData;
use App\Exceptions\GitBranchDoesNotExist;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\WorkspaceDirectoryExists;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeGitService;

beforeEach(function () {
    $this->git = new FakeGitService;
    $this->repo = '/tmp/repo';
    $this->worktree = '/tmp/repo-feature';
});

describe('addWorktree', function () {
    beforeEach(function () {
        $this->existingPath = null;

        File::shouldReceive('exists')->andReturnUsing(fn (string $path) => $path === $this->existingPath);
    });

    it('adds a worktree for a branch that already exists', function () {
        $this->git->responses = [
            ['ok' => true],
            ['ok' => true],
        ];

        $worktree = $this->git->addWorktree($this->repo, $this->worktree, 'feature', null);

        expect($worktree)->toBeInstanceOf(WorktreeData::class)
            ->and($worktree->is_primary)->toBeFalse()
            ->and($worktree->path)->toBe($this->worktree)
            ->and($worktree->branch)->toBe('feature')
            ->and($worktree->sha)->toBeNull()
            ->and($this->git->commands)->toBe([
                ['git show-ref --verify --quiet "refs/heads/feature"', $this->repo],
                ['git worktree add "/tmp/repo-feature" "feature"', $this->repo],
            ]);
    });

    it('creates the branch from the base branch when a base branch is given', function () {
        $this->git->responses = [
            ['ok' => false],
            ['ok' => true],
            ['ok' => true],
        ];

        $worktree = $this->git->addWorktree($this->repo, $this->worktree, 'feature', 'main');

        expect($worktree->branch)->toBe('feature')
            ->and($worktree->path)->toBe($this->worktree)
            ->and($this->git->commands)->toBe([
                ['git show-ref --verify --quiet "refs/heads/feature"', $this->repo],
                ['git show-ref --verify --quiet "refs/heads/main"', $this->repo],
                ['git worktree add -b "feature" "/tmp/repo-feature" "main"', $this->repo],
            ]);
    });

    it('prefers the base branch even when the branch already exists', function () {
        $this->git->responses = [
            ['ok' => true],
            ['ok' => true],
            ['ok' => true],
        ];

        $this->git->addWorktree($this->repo, $this->worktree, 'feature', 'main');

        expect($this->git->commands[2][0])->toBe('git worktree add -b "feature" "/tmp/repo-feature" "main"');
    });

    it('throws before running git when the workspace directory already exists', function () {
        $this->existingPath = $this->worktree;

        expect(fn () => $this->git->addWorktree($this->repo, $this->worktree, 'feature', null))
            ->toThrow(WorkspaceDirectoryExists::class, "Workspace with directory '/tmp/repo-feature' already exists.")
            ->and($this->git->commands)->toBe([]);
    });

    it('throws when the base branch does not exist', function () {
        $this->git->responses = [
            ['ok' => false],
            ['ok' => false],
        ];

        expect(fn () => $this->git->addWorktree($this->repo, $this->worktree, 'feature', 'nope'))
            ->toThrow(GitBranchDoesNotExist::class, 'Branch "nope" does not exist in git repository "/tmp/repo"')
            ->and($this->git->commands)->toHaveCount(2);
    });

    it('throws when neither the branch exists nor a base branch is given', function () {
        $this->git->responses = [['ok' => false]];

        expect(fn () => $this->git->addWorktree($this->repo, $this->worktree, 'feature', null))
            ->toThrow(RuntimeException::class, 'Branch must exist or base branch must be provided; mutually exclusive')
            ->and($this->git->commands)->toHaveCount(1);
    });

    it('surfaces git stderr when adding a worktree for an existing branch fails', function () {
        $this->git->responses = [
            ['ok' => true],
            ['ok' => false, 'err' => "fatal: 'feature' is already used by worktree"],
        ];

        expect(fn () => $this->git->addWorktree($this->repo, $this->worktree, 'feature', null))
            ->toThrow(GitOperationFailed::class, "Failed to add worktree (existing branch): fatal: 'feature' is already used by worktree");
    });

    it('surfaces git stderr when adding a worktree for a new branch fails', function () {
        $this->git->responses = [
            ['ok' => false],
            ['ok' => true],
            ['ok' => false, 'err' => 'fatal: could not create work tree dir'],
        ];

        expect(fn () => $this->git->addWorktree($this->repo, $this->worktree, 'feature', 'main'))
            ->toThrow(GitOperationFailed::class, 'Failed to add worktree (new branch): fatal: could not create work tree dir');
    });
});

describe('removeWorktree', function () {
    it('removes a worktree in the worktree directory and keeps the branch', function () {
        $this->git->removeWorktree($this->repo, $this->worktree, 'feature', false, false, false);

        expect($this->git->commands)->toBe([
            ['git worktree remove /tmp/repo-feature', $this->worktree],
        ]);
    });

    it('forces the removal when asked', function () {
        $this->git->removeWorktree($this->repo, $this->worktree, 'feature', true, false, false);

        expect($this->git->commands)->toBe([
            ['git worktree remove --force /tmp/repo-feature', $this->worktree],
        ]);
    });

    it('deletes the branch in the main worktree', function () {
        $this->git->removeWorktree($this->repo, $this->worktree, 'feature', false, true, false);

        expect($this->git->commands)->toBe([
            ['git worktree remove /tmp/repo-feature', $this->worktree],
            ['git branch --delete feature', $this->repo],
        ]);
    });

    it('force deletes the branch when asked', function () {
        $this->git->removeWorktree($this->repo, $this->worktree, 'feature', false, true, true);

        expect($this->git->commands[1])->toBe(['git branch --delete --force feature', $this->repo]);
    });

    it('throws when the worktree removal fails and never deletes the branch', function () {
        $this->git->responses = [
            ['ok' => false, 'err' => 'fatal: contains modified or untracked files'],
        ];

        expect(fn () => $this->git->removeWorktree($this->repo, $this->worktree, 'feature', false, true, false))
            ->toThrow(GitOperationFailed::class, 'Failed to remove worktree: fatal: contains modified or untracked files')
            ->and($this->git->commands)->toHaveCount(1);
    });

    it('reports the forced removal in the failure message', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: nope']];

        expect(fn () => $this->git->removeWorktree($this->repo, $this->worktree, 'feature', true, false, false))
            ->toThrow(GitOperationFailed::class, 'Failed to remove worktree (forced): fatal: nope');
    });

    it('throws when the branch deletion fails after the worktree is already gone', function () {
        $this->git->responses = [
            ['ok' => true],
            ['ok' => false, 'err' => "error: the branch 'feature' is not fully merged"],
        ];

        expect(fn () => $this->git->removeWorktree($this->repo, $this->worktree, 'feature', false, true, false))
            ->toThrow(GitOperationFailed::class, "Failed to delete branch: error: the branch 'feature' is not fully merged");
    });
});

describe('listWorktrees', function () {
    it('parses the primary and linked worktrees', function () {
        $this->git->responses = [['ok' => true, 'out' => implode("\n\n", [
            worktreeRecord('/tmp/repo', 'aaa111', 'main'),
            worktreeRecord('/tmp/repo-feature', 'bbb222', 'feature'),
            worktreeRecord('/tmp/repo-release', 'ccc333', 'release/1.0'),
        ])."\n"]];

        $worktrees = $this->git->listWorktrees($this->repo);

        expect($worktrees)->toHaveCount(3)
            ->and($worktrees->pluck('is_primary')->all())->toBe([true, false, false])
            ->and($worktrees->pluck('path')->all())->toBe(['/tmp/repo', '/tmp/repo-feature', '/tmp/repo-release'])
            ->and($worktrees->pluck('branch')->all())->toBe(['main', 'feature', 'release/1.0'])
            ->and($worktrees->first()->sha)->toBe('aaa111')
            ->and($this->git->commands)->toBe([['git worktree list --porcelain', $this->repo]]);
    });

    it('excludes bare worktrees but still counts them when marking the primary', function () {
        $this->git->responses = [['ok' => true, 'out' => implode("\n\n", [
            worktreeRecord('/tmp/repo.git', bare: true),
            worktreeRecord('/tmp/repo-feature', 'bbb222', 'feature'),
        ])]];

        $worktrees = $this->git->listWorktrees($this->repo);

        expect($worktrees)->toHaveCount(1)
            ->and($worktrees->first()->path)->toBe('/tmp/repo-feature')
            ->and($worktrees->first()->is_primary)->toBeFalse();
    });

    it('returns a null branch for a detached worktree', function () {
        $this->git->responses = [['ok' => true, 'out' => implode("\n\n", [
            worktreeRecord('/tmp/repo', 'aaa111', 'main'),
            worktreeRecord('/tmp/repo-detached', 'bbb222'),
        ])]];

        $worktrees = $this->git->listWorktrees($this->repo);

        expect($worktrees->last()->branch)->toBeNull()
            ->and($worktrees->last()->sha)->toBe('bbb222');
    });

    it('throws when git fails', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->git->listWorktrees($this->repo))
            ->toThrow(GitOperationFailed::class, 'Failed to list worktrees: fatal: not a git repository');
    });
});

describe('listLocalBranches', function () {
    it('parses the branch names', function () {
        $this->git->responses = [['ok' => true, 'out' => "feature\nmain\nrelease/1.0\n"]];

        expect($this->git->listLocalBranches($this->repo)->all())->toBe(['feature', 'main', 'release/1.0'])
            ->and($this->git->commands)->toBe([
                ["git for-each-ref --format='%(refname:short)' refs/heads", $this->repo],
            ]);
    });

    it('returns an empty collection when there are no branches', function () {
        $this->git->responses = [['ok' => true, 'out' => "\n"]];

        expect($this->git->listLocalBranches($this->repo))->toBeEmpty();
    });

    it('throws when git fails', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->git->listLocalBranches($this->repo))
            ->toThrow(GitOperationFailed::class, 'Failed to list local branches: fatal: not a git repository');
    });
});

describe('status', function () {
    it('parses the porcelain entries', function () {
        $this->git->responses = [['ok' => true, 'out' => " M src/a.php\nA  b.php\n?? c.php\n"]];

        $entries = $this->git->status($this->repo);

        expect($entries)->toHaveCount(3)
            ->and($entries->first())->toBeInstanceOf(GitStatusEntryData::class)
            ->and($entries->pluck('code')->all())->toBe([' M', 'A ', '??'])
            ->and($entries->pluck('path')->all())->toBe(['src/a.php', 'b.php', 'c.php'])
            ->and($this->git->commands)->toBe([['git status --porcelain', $this->repo]]);
    });

    it('drops lines shorter than four characters', function () {
        $this->git->responses = [['ok' => true, 'out' => " M a.php\n??\n\nA  b.php\n"]];

        expect($this->git->status($this->repo)->pluck('path')->all())->toBe(['a.php', 'b.php']);
    });

    it('returns an empty collection for a clean repository', function () {
        $this->git->responses = [['ok' => true, 'out' => '']];

        expect($this->git->status($this->repo))->toBeEmpty();
    });

    it('keeps the rename arrow in the path', function () {
        $this->git->responses = [['ok' => true, 'out' => "R  old.txt -> new.txt\n"]];

        $entry = $this->git->status($this->repo)->sole();

        expect($entry->code)->toBe('R ')
            ->and($entry->path)->toBe('old.txt -> new.txt');
    });

    it('throws when git fails', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->git->status($this->repo))
            ->toThrow(GitOperationFailed::class, 'Failed to get status: fatal: not a git repository');
    });

    it('labels a porcelain code', function (string $code, string $label) {
        expect((new GitStatusEntryData(code: $code, path: 'file.txt'))->label())->toBe($label);
    })->with([
        'untracked' => ['??', 'untracked'],
        'renamed' => ['R ', 'renamed'],
        'deleted' => [' D', 'deleted'],
        'added' => ['A ', 'added'],
        'modified' => [' M', 'modified'],
        'added before modified' => ['AM', 'added'],
        'deleted before modified' => ['MD', 'deleted'],
        'unknown falls back to the trimmed code' => ['UU', 'UU'],
    ]);
});

describe('isStatusClean', function () {
    it('is true when there are no changes', function () {
        $this->git->responses = [['ok' => true, 'out' => '']];

        expect($this->git->isStatusClean($this->repo))->toBeTrue();
    });

    it('is false when there are changes', function () {
        $this->git->responses = [['ok' => true, 'out' => "?? a.php\n"]];

        expect($this->git->isStatusClean($this->repo))->toBeFalse();
    });

    it('throws when git fails', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->git->isStatusClean($this->repo))
            ->toThrow(GitOperationFailed::class, 'Failed to get status: fatal: not a git repository');
    });
});

describe('commitAll', function () {
    it('stages every change and then commits', function () {
        $this->git->commitAll($this->repo, 'work in progress');

        expect($this->git->commands)->toBe([
            ['git add --all', $this->repo],
            ["git commit --message 'work in progress'", $this->repo],
        ]);
    });

    it('escapes a message containing quotes', function () {
        $message = <<<'MESSAGE'
        it's a "test"
        MESSAGE;

        $expected = <<<'COMMAND'
        git commit --message 'it'\''s a "test"'
        COMMAND;

        $this->git->commitAll($this->repo, $message);

        expect($this->git->commands[1][0])->toBe($expected);
    });

    it('throws when staging fails and never commits', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->git->commitAll($this->repo, 'nope'))
            ->toThrow(GitOperationFailed::class, 'Failed to stage all changes: fatal: not a git repository')
            ->and($this->git->commands)->toHaveCount(1);
    });

    it('throws when the commit fails', function () {
        $this->git->responses = [
            ['ok' => true],
            ['ok' => false, 'err' => 'fatal: cannot lock ref HEAD'],
        ];

        expect(fn () => $this->git->commitAll($this->repo, 'nope'))
            ->toThrow(GitOperationFailed::class, 'Failed to commit changes: fatal: cannot lock ref HEAD');
    });

    it('falls back to stdout when the commit failure has no stderr', function () {
        $this->git->responses = [
            ['ok' => true],
            ['ok' => false, 'err' => '', 'out' => 'nothing to commit, working tree clean'],
        ];

        expect(fn () => $this->git->commitAll($this->repo, 'nope'))
            ->toThrow(GitOperationFailed::class, 'Failed to commit changes: nothing to commit, working tree clean');
    });
});

describe('addToGitInfoExclude', function () {
    beforeEach(function () {
        $this->excludePath = '/tmp/repo/.git/info/exclude';
        $this->excludeContents = null;
        $this->ensuredDirectories = [];
        $this->appended = [];

        $this->git->responses = [['ok' => true, 'out' => ".git\n"]];

        File::shouldReceive('isFile')
            ->andReturnUsing(fn (string $path): bool => $path === $this->excludePath && $this->excludeContents !== null);

        File::shouldReceive('get')
            ->andReturnUsing(fn (string $path): string => $this->excludeContents ?? '');

        File::shouldReceive('ensureDirectoryExists')
            ->andReturnUsing(function (string $path): void {
                $this->ensuredDirectories[] = $path;
            });

        File::shouldReceive('append')
            ->andReturnUsing(function (string $path, string $contents): int {
                $this->appended[] = [$path, $contents];

                return strlen($contents);
            });
    });

    it('appends the entry to the exclude file', function () {
        $this->excludeContents = "node_modules/\n";

        $this->git->addToGitInfoExclude($this->repo, '/.laborforest/');

        expect($this->git->commands)->toBe([['git rev-parse --git-common-dir', $this->repo]])
            ->and($this->ensuredDirectories)->toBe(['/tmp/repo/.git/info'])
            ->and($this->appended)->toBe([[$this->excludePath, '/.laborforest/'.PHP_EOL]]);
    });

    it('creates the exclude file when the repository has none', function () {
        $this->git->addToGitInfoExclude($this->repo, '/.laborforest/');

        expect($this->ensuredDirectories)->toBe(['/tmp/repo/.git/info'])
            ->and($this->appended)->toBe([[$this->excludePath, '/.laborforest/'.PHP_EOL]]);
    });

    it('uses the absolute directory git reports for a linked worktree', function () {
        $this->git->responses = [['ok' => true, 'out' => "/tmp/repo/.git\n"]];

        $this->git->addToGitInfoExclude($this->worktree, '/.laborforest/');

        expect($this->git->commands)->toBe([['git rev-parse --git-common-dir', $this->worktree]])
            ->and($this->appended)->toBe([[$this->excludePath, '/.laborforest/'.PHP_EOL]]);
    });

    it('separates the entry when the exclude file has no trailing newline', function () {
        $this->excludeContents = 'node_modules/';

        $this->git->addToGitInfoExclude($this->repo, '/.laborforest/');

        expect($this->appended)->toBe([[$this->excludePath, PHP_EOL.'/.laborforest/'.PHP_EOL]]);
    });

    it('writes nothing when the entry is already present', function () {
        $this->excludeContents = "node_modules/\n/.laborforest/\n";

        $this->git->addToGitInfoExclude($this->repo, '/.laborforest/');

        expect($this->appended)->toBe([])
            ->and($this->ensuredDirectories)->toBe([]);
    });

    it('throws and writes nothing when the git directory cannot be located', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->git->addToGitInfoExclude($this->repo, '/.laborforest/'))
            ->toThrow(GitOperationFailed::class, 'Failed to locate the git directory: fatal: not a git repository')
            ->and($this->appended)->toBe([]);
    });
});

describe('currentBranch', function () {
    it('returns the trimmed branch name', function () {
        $this->git->responses = [['ok' => true, 'out' => "feature/x\n"]];

        expect($this->git->currentBranch($this->repo))->toBe('feature/x')
            ->and($this->git->commands)->toBe([['git rev-parse --abbrev-ref HEAD', $this->repo]]);
    });

    it('returns HEAD when the worktree is detached', function () {
        $this->git->responses = [['ok' => true, 'out' => "HEAD\n"]];

        expect($this->git->currentBranch($this->repo))->toBe('HEAD');
    });

    it('throws when git fails', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->git->currentBranch($this->repo))
            ->toThrow(GitOperationFailed::class, 'Failed to get current branch: fatal: not a git repository');
    });
});

describe('doesBranchExist', function () {
    it('is true when the ref resolves', function () {
        expect($this->git->doesBranchExist($this->repo, 'main'))->toBeTrue()
            ->and($this->git->commands)->toBe([
                ['git show-ref --verify --quiet "refs/heads/main"', $this->repo],
            ]);
    });

    it('is false when the ref does not resolve', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect($this->git->doesBranchExist($this->repo, 'nope'))->toBeFalse();
    });
});

describe('isGitRepository', function () {
    it('is true when git resolves the repository', function () {
        expect($this->git->isGitRepository($this->repo))->toBeTrue()
            ->and($this->git->commands)->toBe([['git rev-parse --git-common-dir', $this->repo]]);
    });

    it('is false when the directory is not a repository', function () {
        $this->git->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect($this->git->isGitRepository('/tmp/plain'))->toBeFalse()
            ->and($this->git->commands)->toBe([['git rev-parse --git-common-dir', '/tmp/plain']]);
    });
});

/**
 * Build a single `git worktree list --porcelain` record.
 */
function worktreeRecord(string $path, ?string $sha = null, ?string $branch = null, bool $bare = false): string
{
    $lines = ['worktree '.$path];

    if ($bare) {
        $lines[] = 'bare';

        return implode("\n", $lines);
    }

    $lines[] = 'HEAD '.$sha;

    if ($branch) {
        $lines[] = 'branch refs/heads/'.$branch;
    } else {
        $lines[] = 'detached';
    }

    return implode("\n", $lines);
}
