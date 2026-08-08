<?php

use App\Enums\File as FileName;
use App\Services\ProcessEnvironmentService;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->environment = new ProcessEnvironmentService;
    $this->envPath = base_path(FileName::ENV->value);
    $this->envContents = null;
    $this->hostTerm = getenv('TERM');

    File::shouldReceive('isFile')
        ->andReturnUsing(fn (string $path): bool => $path === $this->envPath && $this->envContents !== null);

    File::shouldReceive('get')
        ->andReturnUsing(fn (string $path): string => (string) $this->envContents);
});

afterEach(function () {
    $this->hostTerm === false ? putenv('TERM') : putenv('TERM='.$this->hostTerm);

    putenv('NATIVEPHP_LABOR_FOREST_TEST');
    putenv('LARAVEL_LABOR_FOREST_TEST');
});

describe('sanitized', function () {
    it('drops a variable this application declares', function (string $name) {
        $sanitized = $this->environment->sanitized();

        expect($sanitized)->toHaveKey($name)
            ->and($sanitized[$name])->toBeFalse();
    })->with([
        'app environment' => ['APP_ENV'],
        'app debug flag' => ['APP_DEBUG'],
        'config cache path' => ['APP_CONFIG_CACHE'],
        'services cache path' => ['APP_SERVICES_CACHE'],
        'packages cache path' => ['APP_PACKAGES_CACHE'],
        'routes cache path' => ['APP_ROUTES_CACHE'],
        'events cache path' => ['APP_EVENTS_CACHE'],
    ]);

    it('drops a runtime injected name found in the host environment', function (string $name) {
        putenv($name.'=leaked');

        $sanitized = $this->environment->sanitized();

        expect($sanitized)->toHaveKey($name)
            ->and($sanitized[$name])->toBeFalse();
    })->with([
        'nativephp prefix' => ['NATIVEPHP_LABOR_FOREST_TEST'],
        'laravel prefix' => ['LARAVEL_LABOR_FOREST_TEST'],
    ]);

    it('drops every key parsed out of the project env file', function () {
        $this->envContents = projectEnvFile([
            'APP_NAME' => '"Labor Forest"',
            'QUEUE_CONNECTION' => 'database',
            'DB_DATABASE' => '/tmp/repo/database.sqlite',
        ]);

        $sanitized = $this->environment->sanitized();

        expect($sanitized)->toHaveKey('APP_NAME')
            ->and($sanitized['APP_NAME'])->toBeFalse()
            ->and($sanitized)->toHaveKey('QUEUE_CONNECTION')
            ->and($sanitized['QUEUE_CONNECTION'])->toBeFalse()
            ->and($sanitized)->toHaveKey('DB_DATABASE')
            ->and($sanitized['DB_DATABASE'])->toBeFalse();
    });

    it('ignores a comment in the project env file', function () {
        $this->envContents = <<<'ENV'
        # a comment is not a key
        APP_NAME="Labor Forest"
        ENV;

        expect($this->environment->sanitized())->toHaveKey('APP_NAME')
            ->and(array_keys($this->environment->sanitized()))->not->toContain('# a comment is not a key');
    });

    it('never drops a preserved name declared by the project env file', function (string $name) {
        $this->envContents = projectEnvFile([
            'PATH' => '/usr/local/bin',
            'HOME' => '/tmp/home',
            'USER' => 'laborforest',
            'SHELL' => '/bin/zsh',
            'TMPDIR' => '/tmp',
            'SSH_AUTH_SOCK' => '/tmp/agent.sock',
            'TERM' => 'dumb',
            'LANG' => 'en_US.UTF-8',
            'LC_ALL' => 'en_US.UTF-8',
            'LC_CTYPE' => 'en_US.UTF-8',
        ]);

        expect($this->environment->sanitized())->not->toHaveKey($name);
    })->with([
        'path' => ['PATH'],
        'home' => ['HOME'],
        'user' => ['USER'],
        'shell' => ['SHELL'],
        'tmpdir' => ['TMPDIR'],
        'ssh agent socket' => ['SSH_AUTH_SOCK'],
        'lang' => ['LANG'],
        'lc all prefix' => ['LC_ALL'],
        'lc ctype prefix' => ['LC_CTYPE'],
    ]);

    it('forces the color hints', function () {
        $sanitized = $this->environment->sanitized();

        expect($sanitized)->toHaveKey('FORCE_COLOR')
            ->and($sanitized['FORCE_COLOR'])->toBe('3')
            ->and($sanitized)->toHaveKey('CLICOLOR_FORCE')
            ->and($sanitized['CLICOLOR_FORCE'])->toBe('1');
    });

    it('forces the color hints over an env file that declares them', function () {
        $this->envContents = projectEnvFile([
            'FORCE_COLOR' => '0',
            'CLICOLOR_FORCE' => '0',
        ]);

        $sanitized = $this->environment->sanitized();

        expect($sanitized['FORCE_COLOR'])->toBe('3')
            ->and($sanitized['CLICOLOR_FORCE'])->toBe('1');
    });

    it('adds a fallback terminal type when the host declares none', function () {
        putenv('TERM');

        $sanitized = $this->environment->sanitized();

        expect($sanitized)->toHaveKey('TERM')
            ->and($sanitized['TERM'])->toBe('xterm-256color');
    });

    it('leaves the terminal type alone when the host declares one', function () {
        putenv('TERM=screen-256color');

        expect($this->environment->sanitized())->not->toHaveKey('TERM');
    });

    it('lets the caller override a stripped name', function () {
        $this->envContents = projectEnvFile(['APP_NAME' => '"Labor Forest"']);

        $sanitized = $this->environment->sanitized([
            'APP_ENV' => 'production',
            'APP_NAME' => 'Workspace',
        ]);

        expect($sanitized['APP_ENV'])->toBe('production')
            ->and($sanitized['APP_NAME'])->toBe('Workspace');
    });

    it('lets the caller override a forced color hint', function () {
        $sanitized = $this->environment->sanitized([
            'FORCE_COLOR' => '0',
            'CLICOLOR_FORCE' => '0',
        ]);

        expect($sanitized['FORCE_COLOR'])->toBe('0')
            ->and($sanitized['CLICOLOR_FORCE'])->toBe('0');
    });

    it('passes a caller value through for a preserved name', function () {
        $sanitized = $this->environment->sanitized(['PATH' => '/usr/local/bin:/usr/bin']);

        expect($sanitized)->toHaveKey('PATH')
            ->and($sanitized['PATH'])->toBe('/usr/local/bin:/usr/bin');
    });

    it('skips a missing env file without complaining', function () {
        $sanitized = $this->environment->sanitized();

        expect($sanitized)->toHaveKey('APP_ENV')
            ->and($sanitized['APP_ENV'])->toBeFalse()
            ->and($sanitized)->toHaveKey('FORCE_COLOR')
            ->and($sanitized)->not->toHaveKey('APP_NAME');
    });

    it('skips an empty env file without complaining', function () {
        $this->envContents = '';

        $sanitized = $this->environment->sanitized();

        expect($sanitized)->toHaveKey('APP_ENV')
            ->and($sanitized['APP_ENV'])->toBeFalse()
            ->and($sanitized)->toHaveKey('CLICOLOR_FORCE');
    });

    it('throws when the project env file cannot be parsed', function () {
        $this->envContents = <<<'ENV'
        APP NAME=broken
        ENV;

        expect(fn () => $this->environment->sanitized())
            ->toThrow(InvalidFileException::class, 'Failed to parse dotenv file. Encountered an invalid name at [APP NAME].')
            ->and(fn () => $this->environment->sanitized())
            ->toThrow(InvalidFileException::class, 'Failed to parse dotenv file. Encountered an invalid name at [APP NAME].');
    });

    it('caches nothing when the project env file cannot be parsed', function () {
        $this->envContents = <<<'ENV'
        APP NAME=broken
        ENV;

        expect(fn () => $this->environment->sanitized())->toThrow(InvalidFileException::class);

        $this->envContents = projectEnvFile(['APP_NAME' => '"Labor Forest"']);

        expect($this->environment->sanitized())->toHaveKey('APP_NAME')
            ->and($this->environment->sanitized()['APP_NAME'])->toBeFalse();
    });

    it('memoizes the leaked names for the life of an instance', function () {
        $this->envContents = projectEnvFile(['APP_NAME' => '"Labor Forest"']);

        $first = $this->environment->sanitized();

        $this->envContents = projectEnvFile(['OTHER_NAME' => 'later']);

        $second = $this->environment->sanitized();

        expect($first)->toHaveKey('APP_NAME')
            ->and($second)->toHaveKey('APP_NAME')
            ->and($second)->not->toHaveKey('OTHER_NAME');
    });

    it('rereads the env file for a freshly built instance', function () {
        $this->envContents = projectEnvFile(['APP_NAME' => '"Labor Forest"']);

        $this->environment->sanitized();

        $this->envContents = projectEnvFile(['OTHER_NAME' => 'later']);

        $sanitized = (new ProcessEnvironmentService)->sanitized();

        expect($sanitized)->toHaveKey('OTHER_NAME')
            ->and($sanitized['OTHER_NAME'])->toBeFalse()
            ->and($sanitized)->not->toHaveKey('APP_NAME');
    });
});

/**
 * Dump the contents of this application's own .env file from already-quoted values.
 *
 * @param  array<string, string>  $values
 */
function projectEnvFile(array $values): string
{
    $lines = [];

    foreach ($values as $name => $value) {
        $lines[] = $name.'='.$value;
    }

    return implode("\n", $lines)."\n";
}
