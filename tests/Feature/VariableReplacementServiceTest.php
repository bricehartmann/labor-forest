<?php

use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Enums\GitStatus;
use App\Enums\WorkspaceStatus;
use App\Exceptions\UnresolvedVariable;
use App\Services\VariableReplacementService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->variables = new VariableReplacementService;
    $this->repo = '/tmp/repo';
    $this->worktree = '/tmp/repo-feature';
    $this->project = projectData($this->repo);
    $this->workspace = workspaceData($this->worktree);
});

describe('replace', function () {
    beforeEach(function () {
        $this->envContents = null;
        $this->envReads = [];

        File::shouldReceive('isFile')
            ->andReturnUsing(fn (string $path) => $path === $this->worktree.'/.env' && $this->envContents !== null);

        File::shouldReceive('get')->andReturnUsing(function (string $path): string {
            $this->envReads[] = $path;

            return $this->envContents;
        });
    });

    it('expands an enumerated variable', function (string $content, string $expected) {
        expect($this->variables->replace($this->project, $this->workspace, $content))->toBe($expected);
    })->with([
        'project primary dir' => ['{{ PROJECT_PRIMARY_DIR }}', '/tmp/repo'],
        'project slug kebab' => ['{{ PROJECT_SLUG_KEBAB }}', 'repo'],
        'project slug snake' => ['{{ PROJECT_SLUG_SNAKE }}', 'repo'],
        'workspace dir' => ['{{ WORKSPACE_DIR }}', '/tmp/repo-feature'],
        'workspace slug kebab' => ['{{ WORKSPACE_SLUG_KEBAB }}', 'repo-feature'],
        'workspace slug snake' => ['{{ WORKSPACE_SLUG_SNAKE }}', 'repo_feature'],
    ]);

    it('slugs a multi word directory name in both kebab and snake case', function () {
        $project = projectData('/tmp/Labor Forest');
        $workspace = workspaceData('/tmp/Labor Forest Feature');

        $content = '{{ PROJECT_SLUG_KEBAB }} {{ PROJECT_SLUG_SNAKE }} {{ WORKSPACE_SLUG_KEBAB }} {{ WORKSPACE_SLUG_SNAKE }}';

        expect($this->variables->replace($project, $workspace, $content))
            ->toBe('labor-forest labor_forest labor-forest-feature labor_forest_feature');
    });

    it('tolerates whitespace inside the braces', function (string $content) {
        expect($this->variables->replace($this->project, $this->workspace, $content))->toBe('/tmp/repo-feature');
    })->with([
        'none' => ['{{WORKSPACE_DIR}}'],
        'single spaces' => ['{{ WORKSPACE_DIR }}'],
        'ragged spaces' => ['{{   WORKSPACE_DIR  }}'],
        'tabs' => ["{{\tWORKSPACE_DIR\t}}"],
        'newlines' => ["{{\nWORKSPACE_DIR\n}}"],
    ]);

    it('matches a placeholder that spans several lines', function () {
        $content = <<<'CONTENT'
        cd {{
            WORKSPACE_DIR
        }} && ls
        CONTENT;

        expect($this->variables->replace($this->project, $this->workspace, $content))->toBe('cd /tmp/repo-feature && ls');
    });

    it('replaces every placeholder in the content', function () {
        $this->envContents = envFile(['APP_PORT' => '8080']);

        $content = <<<'CONTENT'
        cd {{ WORKSPACE_DIR }}
        php artisan serve --port={{ ENV_APP_PORT }} --name="{{ WORKSPACE_SLUG_KEBAB }}"
        # from {{ PROJECT_PRIMARY_DIR }}
        CONTENT;

        $expected = <<<'CONTENT'
        cd /tmp/repo-feature
        php artisan serve --port=8080 --name="repo-feature"
        # from /tmp/repo
        CONTENT;

        expect($this->variables->replace($this->project, $this->workspace, $content))->toBe($expected);
    });

    it('returns content without placeholders unchanged', function () {
        $content = <<<'CONTENT'
        echo "nothing to expand { here } either"
        CONTENT;

        expect($this->variables->replace($this->project, $this->workspace, $content))->toBe($content)
            ->and($this->envReads)->toBe([]);
    });

    it('returns an empty string unchanged', function () {
        expect($this->variables->replace($this->project, $this->workspace, ''))->toBe('');
    });

    it('reads an environment passthrough from the workspace env file', function () {
        $this->envContents = envFile([
            'APP_URL' => 'http://localhost',
            'DB_DATABASE' => 'forest',
        ]);

        expect($this->variables->replace($this->project, $this->workspace, '{{ ENV_APP_URL }}/{{ ENV_DB_DATABASE }}'))
            ->toBe('http://localhost/forest')
            ->and($this->envReads)->toBe(['/tmp/repo-feature/.env']);
    });

    it('resolves an env entry that has no value to an empty string', function () {
        $this->envContents = "APP_URL\n";

        expect($this->variables->replace($this->project, $this->workspace, 'url=[{{ ENV_APP_URL }}]'))->toBe('url=[]');
    });

    it('does not read the workspace env file when there is none', function () {
        expect($this->variables->replace($this->project, $this->workspace, 'cd {{ WORKSPACE_DIR }}'))
            ->toBe('cd /tmp/repo-feature')
            ->and($this->envReads)->toBe([]);
    });

    it('reads the workspace env file once even when no passthrough is used', function () {
        $this->envContents = envFile(['APP_URL' => 'http://localhost']);

        expect($this->variables->replace($this->project, $this->workspace, 'cd {{ WORKSPACE_DIR }} && cd {{ PROJECT_PRIMARY_DIR }}'))
            ->toBe('cd /tmp/repo-feature && cd /tmp/repo')
            ->and($this->envReads)->toBe(['/tmp/repo-feature/.env']);
    });

    it('throws for a placeholder that is not a resolvable variable', function (string $content, string $message) {
        $this->envContents = envFile(['APP_URL' => 'http://localhost']);

        $replaced = 'untouched';

        expect(function () use (&$replaced, $content) {
            $replaced = $this->variables->replace($this->project, $this->workspace, $content);
        })
            ->toThrow(UnresolvedVariable::class, $message)
            ->and($replaced)->toBe('untouched')
            ->and($this->envReads)->toBe(['/tmp/repo-feature/.env']);
    })->with([
        'unknown name' => ['echo {{ NOPE }}', 'Unknown variable {{ NOPE }}.'],
        'name containing whitespace' => ['{{ PROJECT DIR }}', 'Unknown variable {{ PROJECT DIR }}.'],
        'lowercase environment name' => ['{{ env_app_url }}', 'Unknown variable {{ env_app_url }}.'],
        'digit after the env prefix' => ['{{ ENV_1PASSWORD }}', 'Unknown variable {{ ENV_1PASSWORD }}.'],
        'empty placeholder' => ['{{}}', 'Unknown variable {{}}.'],
    ]);

    it('abandons the whole replacement when one placeholder is unknown', function () {
        $replaced = 'untouched';

        expect(function () use (&$replaced) {
            $replaced = $this->variables->replace($this->project, $this->workspace, 'cd {{ WORKSPACE_DIR }} && echo {{ NOPE }}');
        })
            ->toThrow(UnresolvedVariable::class, 'Unknown variable {{ NOPE }}.')
            ->and($replaced)->toBe('untouched');
    });

    it('throws when the workspace env file does not define the passthrough', function () {
        $this->envContents = envFile(['DB_DATABASE' => 'forest']);

        $replaced = 'untouched';

        expect(function () use (&$replaced) {
            $replaced = $this->variables->replace($this->project, $this->workspace, 'url={{ ENV_APP_URL }}');
        })
            ->toThrow(UnresolvedVariable::class, "Environment variable 'APP_URL' not found in '/tmp/repo-feature/.env'.")
            ->and($replaced)->toBe('untouched')
            ->and($this->envReads)->toBe(['/tmp/repo-feature/.env']);
    });

    it('throws when there is no workspace env file at all', function () {
        expect(fn () => $this->variables->replace($this->project, $this->workspace, 'url={{ ENV_APP_URL }}'))
            ->toThrow(UnresolvedVariable::class, "Environment variable 'APP_URL' not found in '/tmp/repo-feature/.env'.")
            ->and($this->envReads)->toBe([]);
    });

    it('throws when the regular expression engine gives up part way through', function () {
        $backtrackLimit = ini_get('pcre.backtrack_limit');

        ini_set('pcre.backtrack_limit', '1000');

        $replaced = 'untouched';

        try {
            expect(function () use (&$replaced) {
                $replaced = $this->variables->replace($this->project, $this->workspace, '{{'.str_repeat('a', 100_000).'}}');
            })
                ->toThrow(UnresolvedVariable::class, 'Failed to replace variables: Backtrack limit exhausted.')
                ->and($replaced)->toBe('untouched');
        } finally {
            ini_set('pcre.backtrack_limit', $backtrackLimit);
        }
    });
});

/**
 * Build a project rooted at the given primary directory.
 */
function projectData(string $path): ProjectData
{
    return new ProjectData(
        uuid: '00000000-0000-4000-8000-000000000000',
        path: $path,
        last_opened: 0,
    );
}

/**
 * Build a linked workspace at the given worktree directory.
 */
function workspaceData(string $path): WorkspaceData
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
 * Dump the contents of a workspace .env file.
 *
 * @param  array<string, string>  $values
 */
function envFile(array $values): string
{
    $lines = [];

    foreach ($values as $key => $value) {
        $lines[] = $key.'='.$value;
    }

    return implode("\n", $lines)."\n";
}
