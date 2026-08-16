<?php

use Illuminate\Support\Facades\File;
use Native\Desktop\Drivers\Electron\ElectronServiceProvider;

beforeEach(function () {
    $this->destination = ElectronServiceProvider::electronPath('build/entitlements.mac.plist');
    $this->adhocSource = resource_path('nativephp/entitlements.mac.plist');
    $this->defaultSource = resource_path('nativephp/entitlements.mac.default.plist');

    // Pinned so the real .env cannot decide which profile the auto-detection picks.
    notarizationCredentials(null, null, null);
});

/**
 * Set the notarization credentials the command reads to choose a profile.
 */
function notarizationCredentials(?string $appleId, ?string $appleIdPass, ?string $appleTeamId): void
{
    config([
        'nativephp-internal.notarization.apple_id' => $appleId,
        'nativephp-internal.notarization.apple_id_pass' => $appleIdPass,
        'nativephp-internal.notarization.apple_team_id' => $appleTeamId,
    ]);
}

/**
 * Expect a single copy from the given source onto the Electron entitlements path.
 */
function expectEntitlementsCopiedFrom(string $source, string $destination): void
{
    File::partialMock()
        ->shouldReceive('copy')
        ->once()
        ->with($source, $destination)
        ->andReturnTrue();
}

describe('app:patch-mac-entitlements', function () {
    it('copies the ad-hoc entitlements when no notarization credentials are set', function () {
        expectEntitlementsCopiedFrom($this->adhocSource, $this->destination);

        $this->artisan('app:patch-mac-entitlements')
            ->expectsOutputToContain('NATIVEPHP_APPLE_ID, NATIVEPHP_APPLE_ID_PASS, NATIVEPHP_APPLE_TEAM_ID')
            ->assertSuccessful();
    });

    it('copies NativePHP\'s own entitlements when every notarization credential is set', function () {
        notarizationCredentials('apple@example.com', 'app-specific-password', 'TEAMID1234');

        expectEntitlementsCopiedFrom($this->defaultSource, $this->destination);

        $this->artisan('app:patch-mac-entitlements')->assertSuccessful();
    });

    it('treats a blank credential as missing', function (string $blankKey) {
        notarizationCredentials(
            $blankKey === 'apple_id' ? '' : 'apple@example.com',
            $blankKey === 'apple_id_pass' ? '' : 'app-specific-password',
            $blankKey === 'apple_team_id' ? '' : 'TEAMID1234',
        );

        expectEntitlementsCopiedFrom($this->adhocSource, $this->destination);

        $this->artisan('app:patch-mac-entitlements')->assertSuccessful();
    })->with(['apple_id', 'apple_id_pass', 'apple_team_id']);

    it('copies NativePHP\'s own entitlements with --default despite missing credentials', function () {
        expectEntitlementsCopiedFrom($this->defaultSource, $this->destination);

        $this->artisan('app:patch-mac-entitlements', ['--default' => true])->assertSuccessful();
    });

    it('copies the ad-hoc entitlements with --adhoc despite complete credentials', function () {
        notarizationCredentials('apple@example.com', 'app-specific-password', 'TEAMID1234');

        expectEntitlementsCopiedFrom($this->adhocSource, $this->destination);

        $this->artisan('app:patch-mac-entitlements', ['--adhoc' => true])->assertSuccessful();
    });

    it('fails without copying when both overrides are given', function () {
        File::partialMock()->shouldNotReceive('copy');

        $this->artisan('app:patch-mac-entitlements', ['--adhoc' => true, '--default' => true])->assertFailed();
    });

    it('fails without copying when the source is missing', function () {
        $files = File::partialMock();
        $files->shouldReceive('exists')->andReturnFalse();
        $files->shouldNotReceive('copy');

        $this->artisan('app:patch-mac-entitlements')->assertFailed();
    });

    it('fails without copying when the electron build resources are missing', function () {
        $files = File::partialMock();
        $files->shouldReceive('isDirectory')->andReturnFalse();
        $files->shouldNotReceive('copy');

        $this->artisan('app:patch-mac-entitlements')->assertFailed();
    });
})->skip(PHP_OS_FAMILY !== 'Darwin', 'The entitlements patch is a no-op outside macOS.');
