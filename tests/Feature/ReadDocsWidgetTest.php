<?php

use App\Filament\Widgets\ReadDocsWidget;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fakes\ProcessSpy;

beforeEach(function () {
    Storage::fake('user_home');
});

describe('render', function () {
    it('renders the heading and description', function () {
        Livewire::test(ReadDocsWidget::class)
            ->assertOk()
            ->assertSee('Read the Docs')
            ->assertSee('Click the button below to open a web browser and view the documentation.');
    });
});

describe('readDocs action', function () {
    it('opens the configured documentation url', function () {
        config()->set('app.docs_url', 'https://example.test/docs/_index.md');

        $spy = ProcessSpy::install();

        Livewire::test(ReadDocsWidget::class)
            ->assertOk()
            ->callAction('readDocs');

        expect($spy->commands)->toBe([
            [['open', 'https://example.test/docs/_index.md'], null],
        ]);
    });
});

describe('docs url', function () {
    it('points at the main branch outside a packaged build', function () {
        expect(config('app.docs_url'))
            ->toBe('https://github.com/bricehartmann/labor-forest/blob/main/docs/_index.md');
    });

    it('points at the release tag in a packaged build', function () {
        $originals = [
            'APP_ENV' => $_SERVER['APP_ENV'] ?? null,
            'NATIVEPHP_APP_VERSION' => $_SERVER['NATIVEPHP_APP_VERSION'] ?? null,
        ];

        $_SERVER['APP_ENV'] = 'production';
        $_SERVER['NATIVEPHP_APP_VERSION'] = 'v1.2.3';

        try {
            $config = require base_path('config/app.php');
        } finally {
            foreach ($originals as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);

                    continue;
                }

                $_SERVER[$key] = $value;
            }
        }

        expect($config['docs_url'])
            ->toBe('https://github.com/bricehartmann/labor-forest/blob/v1.2.3/docs/_index.md');
    });

    it('falls back to the main branch when a packaged build declares no version', function () {
        $originals = [
            'APP_ENV' => $_SERVER['APP_ENV'] ?? null,
            'NATIVEPHP_APP_VERSION' => $_SERVER['NATIVEPHP_APP_VERSION'] ?? null,
        ];

        $_SERVER['APP_ENV'] = 'production';
        $_SERVER['NATIVEPHP_APP_VERSION'] = '';

        try {
            $config = require base_path('config/app.php');
        } finally {
            foreach ($originals as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);

                    continue;
                }

                $_SERVER[$key] = $value;
            }
        }

        expect($config['docs_url'])
            ->toBe('https://github.com/bricehartmann/labor-forest/blob/main/docs/_index.md');
    });
});
