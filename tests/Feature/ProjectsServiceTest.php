<?php

use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Enums\GitStatus;
use App\Enums\WorkspaceStatus;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\GitStatusNotClean;
use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\ProjectDirectoryExists;
use App\Exceptions\ProjectDirectoryNotFound;
use App\Exceptions\ProjectDirectoryNotGitRepository;
use App\Exceptions\ProjectNotFound;
use App\Exceptions\WorkspaceDirectoryExists;
use App\Exceptions\WorkspaceNotFound;
use App\Services\ProcessEnvironmentService;
use App\Services\ProjectsService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Tests\Fakes\FakeProcessEnvironmentService;
use Tests\Fakes\ProcessSpy;

beforeEach(function () {
    $this->disk = Storage::fake('user_home');
    $this->extras = Storage::fake('extras');
    $this->path = '.laborforest/projects.yaml';
    $this->projects = new ProjectsService;
    $this->repo = '/tmp/repo';
    $this->worktree = '/tmp/repo-feature';
    $this->uuid = Str::freezeUuids()->toString();

    Carbon::setTestNow('2026-08-07 12:00:00');

    // the real GitService now; only the processes underneath it are faked
    $this->process = ProcessSpy::install();
    $this->instance(ProcessEnvironmentService::class, new FakeProcessEnvironmentService);

    $this->directories = [];
    $this->files = [];
    $this->existingPaths = [];
    $this->copiedDirectories = [];
    $this->deletedDirectories = [];
    $this->workflowFiles = [];

    File::shouldReceive('isDirectory')
        ->andReturnUsing(fn (string $path): bool => in_array($path, $this->directories, true));

    File::shouldReceive('exists')
        ->andReturnUsing(fn (string $path): bool => in_array($path, $this->existingPaths, true));

    File::shouldReceive('isFile')
        ->andReturnUsing(fn (string $path): bool => array_key_exists($path, $this->files));

    File::shouldReceive('makeDirectory')
        ->andReturnUsing(function (string $path): bool {
            $this->directories[] = $path;

            return true;
        });

    File::shouldReceive('put')
        ->andReturnUsing(function (string $path, string $contents): int {
            $this->files[$path] = $contents;

            return strlen($contents);
        });

    File::shouldReceive('files')
        ->andReturnUsing(fn (string $path): array => $this->workflowFiles);

    File::shouldReceive('copyDirectory')
        ->andReturnUsing(function (string $source, string $destination): bool {
            $this->copiedDirectories[] = [$source, $destination];
            $this->directories[] = $destination;

            return true;
        });

    File::shouldReceive('deleteDirectory')
        ->andReturnUsing(function (string $path): bool {
            $this->deletedDirectories[] = $path;
            $this->directories = array_values(array_filter($this->directories, fn (string $directory) => $directory !== $path));

            return true;
        });
});

afterEach(function () {
    Str::createUuidsNormally();
    Carbon::setTestNow();
});

describe('listProjectLocalBranches', function () {
    it('returns every local branch when workspaces are not filtered out', function () {
        $this->process->responses = [['ok' => true, 'out' => "feature\nmain\n"]];

        $branches = $this->projects->listProjectLocalBranches($this->repo, false);

        expect($branches->all())->toBe(['feature', 'main'])
            ->and($this->process->commands)->toBe([
                ["git for-each-ref --format='%(refname:short)' refs/heads", $this->repo],
            ]);
    });

    it('rejects the branches that already have a workspace', function () {
        $this->process->responses = [
            ['ok' => true, 'out' => "feature\nmain\nrelease\n"],
            ['ok' => true, 'out' => implode("\n\n", [
                worktreePorcelain($this->repo, 'aaa111', 'main'),
                worktreePorcelain($this->worktree, 'bbb222', 'feature'),
            ])],
            ['ok' => true, 'out' => ''],
            ['ok' => true, 'out' => ''],
        ];

        $branches = $this->projects->listProjectLocalBranches($this->repo, true);

        expect($branches->all())->toBe(['release'])
            ->and($this->process->commands[0][0])->toBe("git for-each-ref --format='%(refname:short)' refs/heads")
            ->and($this->process->commands[1][0])->toBe('git worktree list --porcelain');
    });

    it('keeps every branch when listing the worktrees fails', function () {
        $this->process->responses = [
            ['ok' => true, 'out' => "feature\nmain\n"],
            ['ok' => false, 'err' => 'fatal: not a git repository'],
        ];

        expect($this->projects->listProjectLocalBranches($this->repo, true)->all())->toBe(['feature', 'main']);
    });

    it('throws before listing the worktrees when listing the branches fails', function () {
        $this->process->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->projects->listProjectLocalBranches($this->repo, true))
            ->toThrow(GitOperationFailed::class, 'Failed to list local branches: fatal: not a git repository')
            ->and($this->process->commands)->toHaveCount(1);
    });
});

describe('removeProject', function () {
    it('writes back every project except the removed one', function () {
        $this->disk->put($this->path, projectsFile([
            projectEntry($this->uuid, '/tmp/one', 200),
            projectEntry(secondUuid(), '/tmp/two', 100),
        ]));

        $this->projects->removeProject($this->uuid);

        expect(Yaml::parse($this->disk->get($this->path)))->toHaveCount(1)
            ->and(Yaml::parse($this->disk->get($this->path))[0]['path'])->toBe('/tmp/two');
    });

    it('rewrites the file unchanged when the uuid is unknown', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, '/tmp/one', 200)]));

        $this->projects->removeProject(secondUuid());

        expect(Yaml::parse($this->disk->get($this->path)))->toHaveCount(1)
            ->and(Yaml::parse($this->disk->get($this->path))[0]['uuid'])->toBe($this->uuid);
    });

    it('throws and leaves the file untouched when it is invalid', function () {
        $this->disk->put($this->path, "just a string\n");

        expect(fn () => $this->projects->removeProject($this->uuid))
            ->toThrow(InvalidProjectsFile::class, 'Expected a list of projects, found string.')
            ->and($this->disk->get($this->path))->toBe("just a string\n");
    });

    it('deletes the project base directory when asked to remove it', function () {
        $this->disk->put($this->path, projectsFile([
            projectEntry($this->uuid, '/tmp/one', 200),
            projectEntry(secondUuid(), '/tmp/two', 100),
        ]));

        $this->directories = ['/tmp/one', '/tmp/one/.laborforest', '/tmp/two/.laborforest'];

        $this->projects->removeProject($this->uuid, true);

        expect($this->deletedDirectories)->toBe(['/tmp/one/.laborforest'])
            ->and(Yaml::parse($this->disk->get($this->path)))->toHaveCount(1)
            ->and(Yaml::parse($this->disk->get($this->path))[0]['path'])->toBe('/tmp/two');
    });

    it('keeps the project base directory by default', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, '/tmp/one', 200)]));

        $this->directories = ['/tmp/one', '/tmp/one/.laborforest'];

        $this->projects->removeProject($this->uuid);

        expect($this->deletedDirectories)->toBe([])
            ->and($this->directories)->toContain('/tmp/one/.laborforest');
    });

    it('deletes nothing when the project base directory does not exist', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, '/tmp/one', 200)]));

        $this->projects->removeProject($this->uuid, true);

        expect($this->deletedDirectories)->toBe([])
            ->and(Yaml::parse($this->disk->get($this->path)))->toBe([]);
    });

    it('deletes nothing when the uuid is unknown', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, '/tmp/one', 200)]));

        $this->directories = ['/tmp/one', '/tmp/one/.laborforest'];

        $this->projects->removeProject(secondUuid(), true);

        expect($this->deletedDirectories)->toBe([])
            ->and(Yaml::parse($this->disk->get($this->path)))->toHaveCount(1);
    });

    it('force removes every linked worktree when asked', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        $this->process->responses = [
            ['ok' => true, 'out' => implode("\n\n", [
                worktreePorcelain($this->repo, 'aaa111', 'main'),
                worktreePorcelain($this->worktree, 'bbb222', 'feature'),
            ])],
        ];

        $this->projects->removeProject($this->uuid, false, true);

        expect($this->process->commands)->toBe([
            ['git worktree list --porcelain', $this->repo],
            ['git worktree remove --force '.$this->worktree, $this->worktree],
        ])
            ->and(Yaml::parse($this->disk->get($this->path)))->toBe([]);
    });

    it('runs no git when the worktree flag is off', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        $this->directories = [$this->repo, $this->repo.'/.laborforest'];

        $this->projects->removeProject($this->uuid, true);

        expect($this->process->commands)->toBe([])
            ->and($this->deletedDirectories)->toBe([$this->repo.'/.laborforest']);
    });

    it('runs no git when the uuid is unknown', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        $this->projects->removeProject(secondUuid(), false, true);

        expect($this->process->commands)->toBe([]);
    });

    it('keeps the project registered when a worktree removal fails', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        $this->directories = [$this->repo, $this->repo.'/.laborforest'];
        $this->process->responses = [
            ['ok' => true, 'out' => implode("\n\n", [
                worktreePorcelain($this->repo, 'aaa111', 'main'),
                worktreePorcelain($this->worktree, 'bbb222', 'feature'),
            ])],
            ['ok' => false, 'err' => 'fatal: contains modified or untracked files'],
        ];

        expect(fn () => $this->projects->removeProject($this->uuid, true, true))
            ->toThrow(GitOperationFailed::class, 'Failed to remove worktree (forced): fatal: contains modified or untracked files')
            ->and(Yaml::parse($this->disk->get($this->path)))->toHaveCount(1)
            ->and($this->deletedDirectories)->toBe([]);
    });
});

describe('updateProject', function () {
    it('replaces the matching project and leaves the others alone', function () {
        $this->directories = ['/tmp/one'];
        $this->disk->put($this->path, projectsFile([
            projectEntry($this->uuid, '/tmp/one', 200),
            projectEntry(secondUuid(), '/tmp/two', 100),
        ]));

        $this->projects->updateProject(new ProjectData(
            uuid: $this->uuid,
            path: '/tmp/one',
            last_opened: 500,
            command_launch_ide: 'code .',
        ));

        $written = Yaml::parse($this->disk->get($this->path));

        expect($written)->toHaveCount(2)
            ->and($written[0]['last_opened'])->toBe(500)
            ->and($written[0]['command_launch_ide'])->toBe('code .')
            ->and($written[1]['uuid'])->toBe(secondUuid())
            ->and($this->directories)->toContain('/tmp/one/.laborforest');
    });

    it('reindexes the sorted projects into a list', function () {
        $this->directories = ['/tmp/two'];
        $this->disk->put($this->path, projectsFile([
            projectEntry($this->uuid, '/tmp/one', 100),
            projectEntry(secondUuid(), '/tmp/two', 200),
        ]));

        $this->projects->updateProject(new ProjectData(uuid: secondUuid(), path: '/tmp/two', last_opened: 300));

        expect(array_keys(Yaml::parse($this->disk->get($this->path))))->toBe([0, 1])
            ->and(Yaml::parse($this->disk->get($this->path))[0]['path'])->toBe('/tmp/two');
    });

    it('has already written the file when the project directory is missing', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, '/tmp/one', 200)]));

        expect(fn () => $this->projects->updateProject(new ProjectData(uuid: $this->uuid, path: '/tmp/one', last_opened: 500)))
            ->toThrow(ProjectDirectoryNotFound::class, "Project directory '/tmp/one' not found.")
            ->and(Yaml::parse($this->disk->get($this->path))[0]['last_opened'])->toBe(500)
            ->and($this->directories)->toBe([]);
    });

    it('throws and writes nothing when the projects file is invalid', function () {
        $this->disk->put($this->path, "just a string\n");

        expect(fn () => $this->projects->updateProject(new ProjectData(uuid: $this->uuid, path: '/tmp/one', last_opened: 500)))
            ->toThrow(InvalidProjectsFile::class, 'Expected a list of projects, found string.')
            ->and($this->disk->get($this->path))->toBe("just a string\n");
    });
});

describe('addProject', function () {
    it('appends the project and initializes its base directory', function () {
        $this->directories = [$this->repo];

        $project = $this->projects->addProject($this->repo);

        expect($project)->toBeInstanceOf(ProjectData::class)
            ->and($project->uuid)->toBe($this->uuid)
            ->and($project->path)->toBe($this->repo)
            ->and($project->last_opened)->toBe(Carbon::now()->timestamp)
            ->and(Yaml::parse($this->disk->get($this->path)))->toBe([[
                'uuid' => $this->uuid,
                'path' => $this->repo,
                'last_opened' => Carbon::now()->timestamp,
                'command_launch_ide' => null,
                'command_launch_browser' => null,
                'command_launch_terminal' => null,
            ]])
            ->and($this->process->commands)->toBe([
                ['git rev-parse --git-common-dir', $this->repo],
                ['git status --porcelain', $this->repo],
            ])
            ->and($this->directories)->toContain('/tmp/repo/.laborforest', '/tmp/repo/.laborforest/ignored')
            ->and($this->files['/tmp/repo/.laborforest/ignored/.gitignore'])->toBe("*\n!.gitignore\n")
            ->and($this->files['/tmp/repo/.laborforest/ignored/status.yaml'])->toBe("status: suspended\n");
    });

    it('writes a mapping instead of a list when the stored projects are not already sorted', function () {
        $this->directories = [$this->repo];
        $this->disk->put($this->path, projectsFile([
            projectEntry($this->uuid, '/tmp/one', 100),
            projectEntry(secondUuid(), '/tmp/two', 200),
        ]));

        $this->projects->addProject($this->repo);

        expect(array_keys(Yaml::parse($this->disk->get($this->path))))->toBe([1, 0, 2])
            ->and(fn () => $this->projects->loadProjects())
            ->toThrow(InvalidProjectsFile::class, 'Expected a list of projects, found a mapping.');
    });

    it('throws before reading the projects file when the directory does not exist', function () {
        expect(fn () => $this->projects->addProject($this->repo))
            ->toThrow(ProjectDirectoryNotFound::class, "Project directory '/tmp/repo' not found.")
            ->and($this->disk->exists($this->path))->toBeFalse()
            ->and($this->process->commands)->toBe([]);
    });

    it('throws before checking for a git directory when the project is already known', function () {
        $this->directories = [$this->repo];
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        expect(fn () => $this->projects->addProject($this->repo))
            ->toThrow(ProjectDirectoryExists::class, "Project with directory '/tmp/repo' already exists.")
            ->and(Yaml::parse($this->disk->get($this->path)))->toHaveCount(1)
            ->and($this->process->commands)->toBe([]);
    });

    it('throws before checking the status when git does not recognize the directory as a repository', function () {
        $this->directories = [$this->repo];
        $this->process->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->projects->addProject($this->repo))
            ->toThrow(ProjectDirectoryNotGitRepository::class, "Project with directory '/tmp/repo' is not a git repository.")
            ->and($this->disk->get($this->path))->toBe('')
            ->and($this->process->commands)->toBe([['git rev-parse --git-common-dir', $this->repo]]);
    });

    it('throws before writing anything when the repository has uncommitted changes', function () {
        $this->directories = [$this->repo];
        $this->process->responses = [
            ['ok' => true],
            ['ok' => true, 'out' => "?? a.php\n"],
        ];

        expect(fn () => $this->projects->addProject($this->repo))
            ->toThrow(GitStatusNotClean::class, "Project with directory '/tmp/repo' has uncommitted changes. Commit or stash them before adding the project.")
            ->and($this->disk->get($this->path))->toBe('')
            ->and($this->process->commands)->toHaveCount(2)
            ->and($this->directories)->toBe([$this->repo]);
    });
});

describe('addProjectWorkspace', function () {
    it('slugs the branch into a sibling directory and seeds the workspace', function () {
        $this->directories = [$this->repo, '/tmp/repo-feature-new-thing', $this->repo.'/.laborforest/workflows'];
        $this->process->responses = [
            ['ok' => true],
            ['ok' => true],
            ['ok' => true, 'out' => worktreePorcelain($this->repo, 'aaa111', 'main')],
            ['ok' => true, 'out' => ''],
        ];

        $workspace = $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature/new thing',
            null,
        );

        expect($workspace)->toBeInstanceOf(WorkspaceData::class)
            ->and($workspace->path)->toBe('/tmp/repo-feature-new-thing')
            ->and($workspace->branch)->toBe('feature/new thing')
            ->and($workspace->is_primary)->toBeFalse()
            ->and($workspace->status)->toBe(WorkspaceStatus::SUSPENDED)
            ->and($workspace->git_status)->toBe(GitStatus::CLEAN)
            ->and($this->process->commands)->toBe([
                ['git show-ref --verify --quiet "refs/heads/feature/new thing"', $this->repo],
                ['git worktree add "/tmp/repo-feature-new-thing" "feature/new thing"', $this->repo],
                ['git worktree list --porcelain', $this->repo],
                ['git status --porcelain', '/tmp/repo-feature-new-thing'],
            ])
            ->and($this->copiedDirectories)->toBe([[
                '/tmp/repo/.laborforest/workflows',
                '/tmp/repo-feature-new-thing/.laborforest/workflows',
            ]]);
    });

    it('reports an unknown git status when the workspace status cannot be read', function () {
        $this->directories = [$this->repo, $this->worktree];
        $this->process->responses = [
            ['ok' => true],
            ['ok' => true],
            ['ok' => true],
            ['ok' => true, 'out' => worktreePorcelain($this->repo, 'aaa111', 'main')],
            ['ok' => false, 'err' => 'fatal: not a git repository'],
        ];

        $workspace = $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature',
            'main',
        );

        expect($workspace->git_status)->toBe(GitStatus::UNKNOWN)
            ->and($this->copiedDirectories)->toBe([]);
    });

    it('seeds from the worktree the base branch is checked out in', function () {
        $this->directories = [$this->repo, '/tmp/repo-feature-new-thing', '/tmp/repo-develop/.laborforest/workflows'];
        $this->process->responses = [
            // the branch, then the base branch, are both checked before the worktree is added
            ['ok' => true],
            ['ok' => true],
            ['ok' => true],
            ['ok' => true, 'out' => implode("\n\n", [
                worktreePorcelain($this->repo, 'aaa111', 'main'),
                worktreePorcelain('/tmp/repo-develop', 'bbb222', 'develop'),
            ])],
            ['ok' => true, 'out' => ''],
        ];

        $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature/new thing',
            'develop',
        );

        expect($this->copiedDirectories)->toBe([[
            '/tmp/repo-develop/.laborforest/workflows',
            '/tmp/repo-feature-new-thing/.laborforest/workflows',
        ]]);
    });

    it('seeds from the branch the project is on when no base branch was chosen', function () {
        $this->directories = [$this->repo, '/tmp/repo-feature-new-thing', '/tmp/repo-main/.laborforest/workflows'];
        $this->process->responses = [
            ['ok' => true],
            ['ok' => true],
            ['ok' => true, 'out' => implode("\n\n", [
                worktreePorcelain('/tmp/repo-main', 'aaa111', 'main'),
                worktreePorcelain('/tmp/repo-develop', 'bbb222', 'develop'),
            ])],
            ['ok' => true, 'out' => ''],
        ];

        $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature/new thing',
            null,
        );

        expect($this->copiedDirectories)->toBe([[
            '/tmp/repo-main/.laborforest/workflows',
            '/tmp/repo-feature-new-thing/.laborforest/workflows',
        ]]);
    });

    it('falls back to the project directory when the base branch has no worktree', function () {
        $this->directories = [$this->repo, '/tmp/repo-feature-new-thing', $this->repo.'/.laborforest/workflows'];
        $this->process->responses = [
            ['ok' => true],
            ['ok' => true],
            ['ok' => true],
            ['ok' => true, 'out' => worktreePorcelain($this->repo, 'aaa111', 'main')],
            ['ok' => true, 'out' => ''],
        ];

        $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature/new thing',
            'gone',
        );

        expect($this->copiedDirectories)->toBe([[
            '/tmp/repo/.laborforest/workflows',
            '/tmp/repo-feature-new-thing/.laborforest/workflows',
        ]]);
    });

    it('leaves the workflows a committed directory already checked out alone', function () {
        $this->directories = [
            $this->repo,
            '/tmp/repo-feature-new-thing',
            '/tmp/repo-feature-new-thing/.laborforest/workflows',
            $this->repo.'/.laborforest/workflows',
        ];

        $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature/new thing',
            null,
        );

        expect($this->copiedDirectories)->toBe([])
            ->and($this->process->commands)->not->toContain(['git worktree list --porcelain', $this->repo]);
    });

    it('throws before running git when the workspace directory already exists', function () {
        $this->directories = [$this->repo, $this->worktree];
        $this->existingPaths = [$this->worktree];

        expect(fn () => $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature',
            null,
        ))
            ->toThrow(WorkspaceDirectoryExists::class, "Workspace with directory '/tmp/repo-feature' already exists.")
            ->and($this->process->commands)->toBe([])
            ->and($this->directories)->toBe([$this->repo, $this->worktree]);
    });

    it('throws before seeding the workspace when the worktree directory was never created', function () {
        $this->directories = [$this->repo];

        expect(fn () => $this->projects->addProjectWorkspace(
            new ProjectData(uuid: $this->uuid, path: $this->repo, last_opened: 200),
            'feature',
            null,
        ))
            ->toThrow(ProjectDirectoryNotFound::class, "Project directory '/tmp/repo-feature' not found.")
            ->and($this->process->commands)->toHaveCount(2)
            ->and($this->files)->toBe([]);
    });
});

describe('loadProjectWorkspaces', function () {
    it('maps every worktree to a workspace', function () {
        $this->process->responses = [
            ['ok' => true, 'out' => implode("\n\n", [
                worktreePorcelain($this->repo, 'aaa111', 'main'),
                worktreePorcelain($this->worktree, 'bbb222', 'feature'),
            ])],
            ['ok' => true, 'out' => ''],
            ['ok' => true, 'out' => "?? a.php\n"],
        ];

        $workspaces = $this->projects->loadProjectWorkspaces($this->repo);

        expect($workspaces)->toHaveCount(2)
            ->and($workspaces->pluck('is_primary')->all())->toBe([true, false])
            ->and($workspaces->pluck('branch')->all())->toBe(['main', 'feature'])
            ->and($workspaces->pluck('status')->all())->toBe([WorkspaceStatus::UNKNOWN, WorkspaceStatus::UNKNOWN])
            ->and($workspaces->pluck('git_status')->all())->toBe([GitStatus::CLEAN, GitStatus::DIRTY])
            ->and($this->process->commands)->toBe([
                ['git worktree list --porcelain', $this->repo],
                ['git status --porcelain', $this->repo],
                ['git status --porcelain', $this->worktree],
            ]);
    });

    it('leaves the workflows of every workspace alone', function () {
        $this->directories = [$this->worktree, $this->repo.'/.laborforest/workflows'];
        $this->process->responses = [
            ['ok' => true, 'out' => implode("\n\n", [
                worktreePorcelain($this->repo, 'aaa111', 'main'),
                worktreePorcelain($this->worktree, 'bbb222', 'feature'),
            ])],
        ];

        $this->projects->loadProjectWorkspaces($this->repo);

        expect($this->copiedDirectories)->toBe([]);
    });

    it('returns an empty collection when listing the worktrees fails', function () {
        $this->process->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect($this->projects->loadProjectWorkspaces($this->repo))->toBeEmpty()
            ->and($this->process->commands)->toHaveCount(1)
            ->and($this->copiedDirectories)->toBe([]);
    });
});

describe('loadProjectWorkspace', function () {
    it('reads the stored status and the git status of the workspace', function () {
        $path = statusFixtureWorkspace($this->files, 'ready');

        $this->process->responses = [
            ['ok' => true, 'out' => worktreePorcelain($path, 'aaa111', 'main')],
            ['ok' => true, 'out' => ''],
        ];

        $workspace = $this->projects->loadProjectWorkspace($path);

        expect($workspace->is_primary)->toBeTrue()
            ->and($workspace->path)->toBe($path)
            ->and($workspace->branch)->toBe('main')
            ->and($workspace->status)->toBe(WorkspaceStatus::READY)
            ->and($workspace->git_status)->toBe(GitStatus::CLEAN);
    });

    it('throws when the path is not one of the listed worktrees', function () {
        $this->process->responses = [
            ['ok' => true, 'out' => worktreePorcelain($this->repo, 'aaa111', 'main')],
        ];

        expect(fn () => $this->projects->loadProjectWorkspace($this->worktree))
            ->toThrow(WorkspaceNotFound::class, "Workspace at path '/tmp/repo-feature' not found.")
            ->and($this->process->commands)->toHaveCount(1)
            ->and($this->files)->toBe([]);
    });

    it('throws when listing the worktrees fails', function () {
        $this->process->responses = [['ok' => false, 'err' => 'fatal: not a git repository']];

        expect(fn () => $this->projects->loadProjectWorkspace($this->worktree))
            ->toThrow(WorkspaceNotFound::class, "Workspace at path '/tmp/repo-feature' not found.")
            ->and($this->process->commands)->toHaveCount(1)
            ->and($this->files)->toBe([]);
    });
});

describe('updateProjectWorkspaceStatus', function () {
    it('writes the status file after seeding the workspace base directory', function () {
        $this->directories = [$this->worktree];

        $this->projects->updateProjectWorkspaceStatus($this->worktree, WorkspaceStatus::WORKING);

        expect($this->files['/tmp/repo-feature/.laborforest/ignored/status.yaml'])->toBe("status: working\n")
            ->and($this->files['/tmp/repo-feature/.laborforest/ignored/.gitignore'])->toBe("*\n!.gitignore\n")
            ->and($this->directories)->toBe([
                $this->worktree,
                '/tmp/repo-feature/.laborforest',
                '/tmp/repo-feature/.laborforest/ignored',
            ]);
    });

    it('overwrites the status seeded by the base directory initialization', function (WorkspaceStatus $status, string $expected) {
        $this->directories = [$this->worktree];

        $this->projects->updateProjectWorkspaceStatus($this->worktree, $status);

        expect($this->files['/tmp/repo-feature/.laborforest/ignored/status.yaml'])->toBe($expected);
    })->with([
        'ready' => [WorkspaceStatus::READY, "status: ready\n"],
        'suspended' => [WorkspaceStatus::SUSPENDED, "status: suspended\n"],
        'error' => [WorkspaceStatus::ERROR, "status: error\n"],
    ]);

    it('throws and writes nothing when the workspace directory does not exist', function () {
        expect(fn () => $this->projects->updateProjectWorkspaceStatus($this->worktree, WorkspaceStatus::WORKING))
            ->toThrow(ProjectDirectoryNotFound::class, "Project directory '/tmp/repo-feature' not found.")
            ->and($this->files)->toBe([])
            ->and($this->directories)->toBe([]);
    });
});

describe('loadProjectWorkspaceStatus', function () {
    it('reads the status stored in the workspace', function () {
        $path = statusFixtureWorkspace($this->files, 'working');

        expect($this->projects->loadProjectWorkspaceStatus($path))->toBe(WorkspaceStatus::WORKING)
            ->and($this->directories)->toBe([]);
    });

    it('returns an unknown status when the file does not exist', function () {
        expect($this->projects->loadProjectWorkspaceStatus($this->worktree))->toBe(WorkspaceStatus::UNKNOWN)
            ->and($this->files)->toBe([]);
    });

    it('throws when the status file is not parseable yaml', function () {
        $path = statusFixtureWorkspace($this->files, 'unparseable');

        expect(fn () => $this->projects->loadProjectWorkspaceStatus($path))
            ->toThrow(ParseException::class, 'Malformed inline YAML string at line 2.')
            ->and($this->directories)->toBe([])
            ->and($this->process->commands)->toBe([]);
    });
});

describe('loadProject', function () {
    it('returns the project matching the uuid', function () {
        $this->directories = [$this->repo];
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        $project = $this->projects->loadProject($this->uuid);

        expect($project->uuid)->toBe($this->uuid)
            ->and($project->last_opened)->toBe(200)
            ->and($this->directories)->toContain('/tmp/repo/.laborforest')
            ->and(Yaml::parse($this->disk->get($this->path))[0]['last_opened'])->toBe(200);
    });

    it('stamps the current time and rewrites the file when touching', function () {
        $this->directories = [$this->repo];
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        $project = $this->projects->loadProject($this->uuid, touch: true);

        expect($project->last_opened)->toBe(Carbon::now()->timestamp)
            ->and(Yaml::parse($this->disk->get($this->path))[0]['last_opened'])->toBe(Carbon::now()->timestamp);
    });

    it('throws before creating anything when the uuid is unknown', function () {
        $this->directories = [$this->repo];
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        expect(fn () => $this->projects->loadProject(secondUuid()))
            ->toThrow(ProjectNotFound::class, "Project with UUID '".secondUuid()."' not found.")
            ->and($this->directories)->toBe([$this->repo])
            ->and($this->files)->toBe([]);
    });

    it('throws when the project directory is gone', function () {
        $this->disk->put($this->path, projectsFile([projectEntry($this->uuid, $this->repo, 200)]));

        expect(fn () => $this->projects->loadProject($this->uuid, touch: true))
            ->toThrow(ProjectDirectoryNotFound::class, "Project directory '/tmp/repo' not found.")
            ->and(Yaml::parse($this->disk->get($this->path))[0]['last_opened'])->toBe(200)
            ->and($this->directories)->toBe([])
            ->and($this->files)->toBe([]);
    });
});

describe('loadProjects', function () {
    it('seeds an empty file when it does not exist', function () {
        $projects = $this->projects->loadProjects();

        expect($projects)->toBeEmpty()
            ->and($this->disk->exists($this->path))->toBeTrue()
            ->and($this->disk->get($this->path))->toBe('');
    });

    it('returns an empty collection for an empty file', function () {
        $this->disk->put($this->path, "\n");

        expect($this->projects->loadProjects())->toBeEmpty();
    });

    it('sorts by last opened without reindexing the keys', function () {
        $this->disk->put($this->path, projectsFile([
            projectEntry($this->uuid, '/tmp/one', 100),
            projectEntry(secondUuid(), '/tmp/two', 300),
            projectEntry(thirdUuid(), '/tmp/three', 200),
        ]));

        $projects = $this->projects->loadProjects();

        expect($projects->pluck('path')->all())->toBe(['/tmp/two', '/tmp/three', '/tmp/one'])
            ->and($projects->keys()->all())->toBe([1, 2, 0])
            ->and($projects->first())->toBeInstanceOf(ProjectData::class);
    });

    it('throws when the file is not parseable yaml', function () {
        $this->disk->put($this->path, "- [unclosed\n");

        expect(fn () => $this->projects->loadProjects())
            ->toThrow(InvalidProjectsFile::class, 'The projects file [.laborforest/projects.yaml] is invalid:')
            ->and($this->disk->get($this->path))->toBe("- [unclosed\n");
    });

    it('throws when the file parses to a scalar', function () {
        $this->disk->put($this->path, "just a string\n");

        expect(fn () => $this->projects->loadProjects())
            ->toThrow(InvalidProjectsFile::class, 'Expected a list of projects, found string.')
            ->and($this->disk->get($this->path))->toBe("just a string\n");
    });

    it('throws when the file parses to a mapping', function () {
        $this->disk->put($this->path, "uuid: {$this->uuid}\n");

        expect(fn () => $this->projects->loadProjects())
            ->toThrow(InvalidProjectsFile::class, 'Expected a list of projects, found a mapping.')
            ->and($this->disk->get($this->path))->toBe("uuid: {$this->uuid}\n");
    });

    it('reports every bad entry rather than only the first', function () {
        $this->disk->put($this->path, projectsFile([
            'not a mapping',
            projectEntry($this->uuid, '/tmp/one', 100),
            ['path' => '/tmp/two', 'last_opened' => 200],
        ]));

        try {
            $this->projects->loadProjects();
        } catch (InvalidProjectsFile $e) {
            expect($e->problems)->toHaveCount(2)
                ->and($e->problems[0])->toBe('Entry 1: expected a mapping, found string.')
                ->and($e->problems[1])->toStartWith('Entry 3: ')
                ->and($e->path)->toBe($this->path)
                ->and($e->getMessage())->toContain('Entry 1: expected a mapping, found string. Entry 3: ');

            return;
        }

        $this->fail('Expected an InvalidProjectsFile exception.');
    });
});

describe('listExampleWorkflowPaths', function () {
    it('returns every example set directory in order', function () {
        exampleWorkflowSet($this->extras, 'laravel');
        exampleWorkflowSet($this->extras, 'bare');
        exampleWorkflowSet($this->extras, 'vite');

        expect($this->projects->listExampleWorkflowPaths()->all())->toBe([
            'example-workflows/bare',
            'example-workflows/laravel',
            'example-workflows/vite',
        ]);
    });

    it('returns nothing when no example sets are bundled', function () {
        expect($this->projects->listExampleWorkflowPaths()->all())->toBe([]);
    });
});

describe('initializeWorkspaceStarterWorkflows', function () {
    it('copies every workflow of the example set into the workspace', function () {
        $this->directories = [$this->worktree];
        exampleWorkflowSet($this->extras, 'laravel', ['up', 'down', 'refresh']);

        $this->projects->initializeWorkspaceStarterWorkflows($this->worktree, 'example-workflows/laravel');

        expect($this->directories)->toBe([
            $this->worktree,
            '/tmp/repo-feature/.laborforest',
            '/tmp/repo-feature/.laborforest/workflows',
        ])
            ->and(collect($this->files)->keys()->sort()->values()->all())->toBe([
                '/tmp/repo-feature/.laborforest/workflows/down.yaml',
                '/tmp/repo-feature/.laborforest/workflows/refresh.yaml',
                '/tmp/repo-feature/.laborforest/workflows/up.yaml',
            ])
            ->and($this->files['/tmp/repo-feature/.laborforest/workflows/up.yaml'])
            ->toBe(exampleWorkflowYaml('up'));
    });

    it('ignores files that are not workflow yaml', function () {
        $this->directories = [$this->worktree];
        exampleWorkflowSet($this->extras, 'laravel');
        $this->extras->put('example-workflows/laravel/notes.yaml', "resource_type: run_log\n");
        $this->extras->put('example-workflows/laravel/broken.yaml', "resource_type: [\n");
        $this->extras->put('example-workflows/laravel/readme.txt', 'resource_type: workflow');

        $this->projects->initializeWorkspaceStarterWorkflows($this->worktree, 'example-workflows/laravel');

        expect(collect($this->files)->keys()->all())->toBe(['/tmp/repo-feature/.laborforest/workflows/up.yaml']);
    });

    it('never overwrites a workflow that already exists', function () {
        $this->directories = [$this->worktree, '/tmp/repo-feature/.laborforest', '/tmp/repo-feature/.laborforest/workflows'];
        $this->files['/tmp/repo-feature/.laborforest/workflows/up.yaml'] = "resource_type: workflow\n";
        exampleWorkflowSet($this->extras, 'laravel', ['up', 'down']);

        $this->projects->initializeWorkspaceStarterWorkflows($this->worktree, 'example-workflows/laravel');

        expect($this->files['/tmp/repo-feature/.laborforest/workflows/up.yaml'])->toBe("resource_type: workflow\n")
            ->and($this->files['/tmp/repo-feature/.laborforest/workflows/down.yaml'])->toBe(exampleWorkflowYaml('down'))
            ->and($this->directories)->toHaveCount(3);
    });

    it('throws when the workspace directory does not exist', function () {
        exampleWorkflowSet($this->extras, 'laravel');

        expect(fn () => $this->projects->initializeWorkspaceStarterWorkflows($this->worktree, 'example-workflows/laravel'))
            ->toThrow(ProjectDirectoryNotFound::class, "Project directory '/tmp/repo-feature' not found.")
            ->and($this->files)->toBe([])
            ->and($this->directories)->toBe([]);
    });
});

describe('doesAnyProjectWorkspaceWorkflowExist', function () {
    beforeEach(function () {
        $this->directories = [$this->worktree.'/.laborforest/workflows'];
    });

    it('is true when a workflow file is present', function () {
        $this->workflowFiles = [workflowFileInfo('up.yaml')];

        expect($this->projects->doesAnyProjectWorkspaceWorkflowExist($this->worktree))->toBeTrue()
            ->and($this->files)->toBe([]);
    });

    it('is false when the workflows directory does not exist', function () {
        $this->directories = [];
        $this->workflowFiles = [workflowFileInfo('up.yaml')];

        expect($this->projects->doesAnyProjectWorkspaceWorkflowExist($this->worktree))->toBeFalse();
    });

    it('is false when the only file is another kind of yaml resource', function () {
        $this->workflowFiles = [workflowFileInfo('log.yaml')];

        expect($this->projects->doesAnyProjectWorkspaceWorkflowExist($this->worktree))->toBeFalse();
    });

    it('ignores files that are not named with a yaml extension', function () {
        $this->workflowFiles = [workflowFileInfo('notes.yml')];

        expect($this->projects->doesAnyProjectWorkspaceWorkflowExist($this->worktree))->toBeFalse();
    });

    it('swallows the parse error of an unreadable workflow file', function () {
        $this->workflowFiles = [
            workflowFileInfo('broken.yaml'),
            workflowFileInfo('missing.yaml'),
        ];

        expect($this->projects->doesAnyProjectWorkspaceWorkflowExist($this->worktree))->toBeFalse()
            ->and($this->files)->toBe([]);
    });
});

/**
 * Build one valid projects.yaml entry.
 *
 * @return array{uuid: string, path: string, last_opened: int}
 */
function projectEntry(string $uuid, string $path, int $lastOpened): array
{
    return ['uuid' => $uuid, 'path' => $path, 'last_opened' => $lastOpened];
}

/**
 * Dump a projects file from raw entries.
 *
 * @param  list<mixed>  $entries
 */
function projectsFile(array $entries): string
{
    return Yaml::dump($entries, inline: 10);
}

/**
 * A second fixed uuid, for the project that is not the one under test.
 */
function secondUuid(): string
{
    return '22222222-2222-4222-8222-222222222222';
}

/**
 * A third fixed uuid, for ordering fixtures.
 */
function thirdUuid(): string
{
    return '33333333-3333-4333-8333-333333333333';
}

/**
 * Build a single `git worktree list --porcelain` record.
 */
function worktreePorcelain(string $path, string $sha, string $branch): string
{
    return implode("\n", [
        'worktree '.$path,
        'HEAD '.$sha,
        'branch refs/heads/'.$branch,
    ]);
}

/**
 * The absolute path of a committed, read-only workspace fixture.
 *
 * Yaml::parseFile() is static and reads the real filesystem, so the two code paths that reach it
 * need a file that really exists. tests/Fixtures is the only sanctioned source; nothing under it is
 * ever written to, and no other test path touches a real file.
 */
function projectFixturePath(string $relativePath): string
{
    return base_path('tests'.DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'projects'.DIRECTORY_SEPARATOR.$relativePath);
}

/**
 * Register the status file of a committed workspace fixture with the doubled File facade, returning
 * the absolute workspace path to hand to the service.
 *
 * @param  array<string, string>  $files
 */
function statusFixtureWorkspace(array &$files, string $name): string
{
    $workspacePath = projectFixturePath($name);

    $statusPath = implode(DIRECTORY_SEPARATOR, [$workspacePath, '.laborforest', 'ignored', 'status.yaml']);

    $files[$statusPath] = '';

    return $workspacePath;
}

/**
 * Build the file info that File::files() would return for a committed workflow fixture. A name with
 * no committed file models one that cannot be read at all.
 */
function workflowFileInfo(string $name): SplFileInfo
{
    $relativePath = implode(DIRECTORY_SEPARATOR, ['.laborforest', 'workflows', $name]);

    return new SplFileInfo(projectFixturePath('workflows'.DIRECTORY_SEPARATOR.$relativePath), '.laborforest'.DIRECTORY_SEPARATOR.'workflows', $relativePath);
}

/**
 * The contents of one bundled example workflow, distinct per name so a copy can be matched to its source.
 */
function exampleWorkflowYaml(string $name): string
{
    return "resource_type: workflow\nsort_order: 0\nsteps:\n  - name: '".$name."'\n    type: shell\n    run: 'true'\n";
}

/**
 * Seed a set of example workflows onto the faked extras disk.
 *
 * @param  array<int, string>  $names
 */
function exampleWorkflowSet(Filesystem $extras, string $set, array $names = ['up']): void
{
    foreach ($names as $name) {
        $extras->put('example-workflows'.DIRECTORY_SEPARATOR.$set.DIRECTORY_SEPARATOR.$name.'.yaml', exampleWorkflowYaml($name));
    }
}
