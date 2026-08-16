<?php

use App\Filament\Widgets\InstallCliToolsWidget;
use App\Filament\Widgets\LegalNoticesWidget;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fakes\ProcessSpy;

beforeEach(function () {
    Storage::fake('user_home');
});

describe('render', function () {
    it('renders the copyright notice', function () {
        Livewire::test(LegalNoticesWidget::class)
            ->assertOk()
            ->assertSee('License')
            ->assertSee('Copyright (C) 2026 Brice Hartmann');
    });

    it('renders the redistribution and warranty notices', function () {
        Livewire::test(LegalNoticesWidget::class)
            ->assertOk()
            ->assertSee('you are welcome to redistribute it')
            ->assertSee('GNU General Public License')
            ->assertSee('ABSOLUTELY NO WARRANTY')
            ->assertSee('View the License');
    });

    // The notice is a condition of conveying the program, so it may never be hidden.
    it('is always visible', function () {
        expect(LegalNoticesWidget::canView())->toBeTrue();
    });

    it('sorts after every other dashboard widget', function () {
        expect(LegalNoticesWidget::getSort())
            ->toBeGreaterThan(InstallCliToolsWidget::getSort());
    });
});

describe('viewLicense action', function () {
    it('opens the configured license url', function () {
        config()->set('app.license_url', 'https://example.test/LICENSE.md');

        $spy = ProcessSpy::install();

        Livewire::test(LegalNoticesWidget::class)
            ->assertOk()
            ->callAction('viewLicense');

        expect($spy->commands)->toBe([
            [['open', 'https://example.test/LICENSE.md'], null],
        ]);
    });
});

describe('license url', function () {
    it('points at the main branch outside a packaged build', function () {
        expect(config('app.license_url'))
            ->toBe('https://github.com/bricehartmann/labor-forest/blob/main/LICENSE.md');
    });

    it('points at the release tag in a packaged build', function () {
        $config = legalNoticesConfigWithServerValues([
            'APP_ENV' => 'production',
            'NATIVEPHP_APP_VERSION' => 'v1.2.3',
        ]);

        expect($config['license_url'])
            ->toBe('https://github.com/bricehartmann/labor-forest/blob/v1.2.3/LICENSE.md');
    });

    it('falls back to the main branch when a packaged build declares no version', function () {
        $config = legalNoticesConfigWithServerValues([
            'APP_ENV' => 'production',
            'NATIVEPHP_APP_VERSION' => '',
        ]);

        expect($config['license_url'])
            ->toBe('https://github.com/bricehartmann/labor-forest/blob/main/LICENSE.md');
    });
});

/**
 * Re-read config/app.php under the given $_SERVER values, restoring them afterwards.
 *
 * @param  array<string, string>  $values
 * @return array<string, mixed>
 */
function legalNoticesConfigWithServerValues(array $values): array
{
    $originals = [];

    foreach ($values as $key => $value) {
        $originals[$key] = $_SERVER[$key] ?? null;
        $_SERVER[$key] = $value;
    }

    try {
        return require base_path('config/app.php');
    } finally {
        foreach ($originals as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);

                continue;
            }

            $_SERVER[$key] = $value;
        }
    }
}
