<?php

use App\Data\ProjectData;
use App\Data\SettingsData;
use App\Data\WorkspaceData;
use App\Enums\GitStatus;
use App\Enums\WorkspaceStatus;
use App\Exceptions\InvalidSettingsFile;
use App\Exceptions\UnresolvedVariable;
use App\Services\LaunchService;
use App\Services\SettingsService;
use App\Services\VariableReplacementService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Storage::fake('user_home');

    $this->repo = '/tmp/repo';
    $this->worktree = '/tmp/repo-feature';

    $this->launcher = new FakeLaunchService;
    $this->settings = new FakeSettingsService;
    $this->variables = new FakeVariableReplacementService;

    $this->settings->settings = new SettingsData(
        command_launch_ide: 'settings-ide "{{ WORKSPACE_DIR }}"',
        command_launch_browser: 'settings-browser "{{ WORKSPACE_DIR }}"',
        command_launch_terminal: 'settings-terminal "{{ WORKSPACE_DIR }}"',
    );

    $this->instance(SettingsService::class, $this->settings);
    $this->instance(VariableReplacementService::class, $this->variables);

    $this->project = launchProjectData();
    $this->workspace = launchWorkspaceData();

    // the workspace has no .env, so every ENV_ passthrough is unresolvable and nothing is read from disk
    File::shouldReceive('isFile')->andReturnFalse();
});

describe('launchTerminal', function () {
    it('starts the settings command when the project defines none', function () {
        $this->launcher->launchTerminal($this->project, $this->workspace);

        expect($this->settings->loads)->toBe(1)
            ->and($this->variables->replacements)->toBe(['settings-terminal "{{ WORKSPACE_DIR }}"'])
            ->and($this->launcher->launches)->toBe([
                ['settings-terminal "/tmp/repo-feature"', $this->worktree],
            ]);
    });

    it('prefers the command the project defines', function () {
        $project = launchProjectData(terminal: 'project-terminal "{{ WORKSPACE_DIR }}"');

        $this->launcher->launchTerminal($project, $this->workspace);

        expect($this->settings->loads)->toBe(0)
            ->and($this->launcher->launches)->toBe([
                ['project-terminal "/tmp/repo-feature"', $this->worktree],
            ]);
    });

    it('throws before starting anything when the command references an unknown variable', function () {
        $project = launchProjectData(terminal: 'project-terminal "{{ NOPE }}"');

        expect(fn () => $this->launcher->launchTerminal($project, $this->workspace))
            ->toThrow(UnresolvedVariable::class, 'Unknown variable {{ NOPE }}.')
            ->and($this->variables->replacements)->toBe(['project-terminal "{{ NOPE }}"'])
            ->and($this->launcher->launches)->toBe([]);
    });

    it('throws before starting anything when the settings file is invalid', function () {
        $this->settings->failure = InvalidSettingsFile::withProblems(
            '.laborforest/settings.yaml',
            ['Expected a mapping, found string.'],
        );

        expect(fn () => $this->launcher->launchTerminal($this->project, $this->workspace))
            ->toThrow(InvalidSettingsFile::class, 'The settings file [.laborforest/settings.yaml] is invalid: Expected a mapping, found string.')
            ->and($this->variables->replacements)->toBe([])
            ->and($this->launcher->launches)->toBe([]);
    });

    it('never reads the settings for an empty project command', function () {
        $this->settings->failure = new RuntimeException('The settings must not be read.');

        $this->launcher->launchTerminal(launchProjectData(terminal: ''), $this->workspace);

        expect($this->settings->loads)->toBe(0)
            ->and($this->variables->replacements)->toBe([])
            ->and($this->launcher->launches)->toBe([]);
    });

    it('does nothing when the command is falsy', function (?string $command) {
        $this->settings->settings = new SettingsData;

        $this->launcher->launchTerminal(launchProjectData(terminal: $command), $this->workspace);

        expect($this->variables->replacements)->toBe([])
            ->and($this->launcher->launches)->toBe([]);
    })->with([
        'null falls through to a null setting' => [null],
        'an empty string' => [''],
        'the string zero' => ['0'],
    ]);
});

describe('launchIde', function () {
    it('starts the settings command when the project defines none', function () {
        $this->launcher->launchIde($this->project, $this->workspace);

        expect($this->settings->loads)->toBe(1)
            ->and($this->variables->replacements)->toBe(['settings-ide "{{ WORKSPACE_DIR }}"'])
            ->and($this->launcher->launches)->toBe([
                ['settings-ide "/tmp/repo-feature"', $this->worktree],
            ]);
    });

    it('prefers the command the project defines', function () {
        $project = launchProjectData(ide: 'project-ide "{{ WORKSPACE_DIR }}"');

        $this->launcher->launchIde($project, $this->workspace);

        expect($this->settings->loads)->toBe(0)
            ->and($this->launcher->launches)->toBe([
                ['project-ide "/tmp/repo-feature"', $this->worktree],
            ]);
    });

    it('expands every placeholder in the command', function () {
        $project = launchProjectData(ide: 'ide "{{ PROJECT_PRIMARY_DIR }}" "{{ WORKSPACE_DIR }}" {{ WORKSPACE_SLUG_SNAKE }} {{ PROJECT_SLUG_KEBAB }}');

        $this->launcher->launchIde($project, $this->workspace);

        expect($this->launcher->launches[0][0])->toBe('ide "/tmp/repo" "/tmp/repo-feature" repo_feature repo')
            ->and($this->launcher->launches[0][1])->toBe($this->worktree);
    });

    it('runs the command in the workspace directory rather than the project directory', function () {
        $workspace = launchWorkspaceData('/tmp/repo-release');

        $this->launcher->launchIde($this->project, $workspace);

        expect($this->launcher->launches)->toBe([
            ['settings-ide "/tmp/repo-release"', '/tmp/repo-release'],
        ])
            ->and($this->launcher->launches[0][1])->not->toBe($this->repo);
    });

    it('throws when the command references an unknown variable', function () {
        $project = launchProjectData(ide: 'project-ide "{{ NOPE }}"');

        expect(fn () => $this->launcher->launchIde($project, $this->workspace))
            ->toThrow(UnresolvedVariable::class, 'Unknown variable {{ NOPE }}.')
            ->and($this->variables->replacements)->toBe(['project-ide "{{ NOPE }}"'])
            ->and($this->launcher->launches)->toBe([]);
    });

    it('throws when the command references an environment variable the workspace does not define', function () {
        $project = launchProjectData(ide: 'project-ide "{{ ENV_APP_URL }}"');

        expect(fn () => $this->launcher->launchIde($project, $this->workspace))
            ->toThrow(UnresolvedVariable::class, "Environment variable 'APP_URL' not found in '/tmp/repo-feature/.env'.")
            ->and($this->launcher->launches)->toBe([]);
    });

    it('does nothing when the command is falsy', function (?string $command) {
        $this->settings->settings = new SettingsData;

        $this->launcher->launchIde(launchProjectData(ide: $command), $this->workspace);

        expect($this->variables->replacements)->toBe([])
            ->and($this->launcher->launches)->toBe([]);
    })->with([
        'null falls through to a null setting' => [null],
        'an empty string' => [''],
        'the string zero' => ['0'],
    ]);
});

describe('launchBrowser', function () {
    it('starts the settings command when the project defines none', function () {
        $this->launcher->launchBrowser($this->project, $this->workspace);

        expect($this->settings->loads)->toBe(1)
            ->and($this->variables->replacements)->toBe(['settings-browser "{{ WORKSPACE_DIR }}"'])
            ->and($this->launcher->launches)->toBe([
                ['settings-browser "/tmp/repo-feature"', $this->worktree],
            ]);
    });

    it('prefers the command the project defines', function () {
        $project = launchProjectData(browser: 'project-browser "{{ WORKSPACE_DIR }}"');

        $this->launcher->launchBrowser($project, $this->workspace);

        expect($this->settings->loads)->toBe(0)
            ->and($this->launcher->launches)->toBe([
                ['project-browser "/tmp/repo-feature"', $this->worktree],
            ]);
    });

    it('throws before starting anything when the default browser command has no APP_URL to expand', function () {
        $this->settings->settings = SettingsData::defaults();

        expect(fn () => $this->launcher->launchBrowser($this->project, $this->workspace))
            ->toThrow(UnresolvedVariable::class, "Environment variable 'APP_URL' not found in '/tmp/repo-feature/.env'.")
            ->and($this->launcher->launches)->toBe([]);
    });

    it('throws when the settings file is invalid', function () {
        $this->settings->failure = InvalidSettingsFile::withProblems(
            '.laborforest/settings.yaml',
            ['Expected a mapping, found string.'],
        );

        expect(fn () => $this->launcher->launchBrowser($this->project, $this->workspace))
            ->toThrow(InvalidSettingsFile::class, 'The settings file [.laborforest/settings.yaml] is invalid: Expected a mapping, found string.')
            ->and($this->variables->replacements)->toBe([])
            ->and($this->launcher->launches)->toBe([]);
    });

    it('does nothing when the command is falsy', function (?string $command) {
        $this->settings->settings = new SettingsData;

        $this->launcher->launchBrowser(launchProjectData(browser: $command), $this->workspace);

        expect($this->variables->replacements)->toBe([])
            ->and($this->launcher->launches)->toBe([]);
    })->with([
        'null falls through to a null setting' => [null],
        'an empty string' => [''],
        'the string zero' => ['0'],
    ]);
});

/**
 * Build a project rooted at /tmp/repo, optionally defining its own launch commands.
 */
function launchProjectData(?string $ide = null, ?string $browser = null, ?string $terminal = null): ProjectData
{
    return new ProjectData(
        uuid: '5f1d0e3a-6a2b-4c8d-9e7f-0a1b2c3d4e5f',
        path: '/tmp/repo',
        last_opened: 1_700_000_000,
        command_launch_ide: $ide,
        command_launch_browser: $browser,
        command_launch_terminal: $terminal,
    );
}

/**
 * Build a ready, clean, linked workspace at the given path.
 */
function launchWorkspaceData(string $path = '/tmp/repo-feature'): WorkspaceData
{
    return new WorkspaceData(
        is_primary: false,
        path: $path,
        branch: 'feature',
        status: WorkspaceStatus::READY,
        git_status: GitStatus::CLEAN,
    );
}

/**
 * A LaunchService whose process construction is replaced by a recorder, so no editor, browser, or
 * terminal is ever spawned and the exact command and working directory can be asserted.
 */
final class FakeLaunchService extends LaunchService
{
    /**
     * Every command handed to launchProcess(), as a [command, cwd] pair.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public array $launches = [];

    protected function launchProcess(string $command, string $cwd): Process
    {
        $this->launches[] = [$command, $cwd];

        $process = Mockery::mock(Process::class);
        $process->allows('setOptions');
        $process->allows('start');

        return $process;
    }
}

/**
 * A SettingsService that answers from memory, counting the lookups so a test can prove a
 * project-level command short-circuited the fallback, and never reading the user's real file.
 */
final class FakeSettingsService extends SettingsService
{
    /**
     * The settings every loadSettings() call reports.
     */
    public SettingsData $settings;

    /**
     * Thrown instead of returning, so a test can prove the failure propagates or that the
     * lookup never happened at all.
     */
    public ?Throwable $failure = null;

    /**
     * How many times loadSettings() was called.
     */
    public int $loads = 0;

    public function loadSettings(): SettingsData
    {
        $this->loads++;

        if ($this->failure) {
            throw $this->failure;
        }

        return $this->settings;
    }
}

/**
 * A VariableReplacementService that records what it was asked to expand while still running the
 * real replacement, so an unresolvable placeholder throws exactly as it does in production.
 */
final class FakeVariableReplacementService extends VariableReplacementService
{
    /**
     * Every content string handed to replace(), in call order.
     *
     * @var array<int, string>
     */
    public array $replacements = [];

    public function replace(ProjectData $projectData, WorkspaceData $workspaceData, string $content): string
    {
        $this->replacements[] = $content;

        return parent::replace($projectData, $workspaceData, $content);
    }
}
