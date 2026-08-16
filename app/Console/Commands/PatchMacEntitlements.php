<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Native\Desktop\Drivers\Electron\ElectronServiceProvider;

#[Signature('app:patch-mac-entitlements
    {--adhoc : Force the ad-hoc overrides, ignoring the notarization credentials}
    {--default : Force NativePHP\'s default entitlements, ignoring the notarization credentials}')]
#[Description('Write the project macOS entitlements over the NativePHP Electron copy, choosing the ad-hoc overrides or NativePHP\'s originals from the notarization credentials')]
class PatchMacEntitlements extends Command
{
    private const ENTITLEMENTS_PATH = 'build/entitlements.mac.plist';

    private const ADHOC_SOURCE = 'nativephp/entitlements.mac.plist';

    private const DEFAULT_SOURCE = 'nativephp/entitlements.mac.default.plist';

    /**
     * The Apple notarization credentials, keyed by the environment variable that feeds each one.
     *
     * All three must be set for a build to reach Apple's notarization service, so their presence is
     * what distinguishes a Developer ID release from a local ad-hoc build.
     *
     * @var array<string, string>
     */
    private const NOTARIZATION_KEYS = [
        'NATIVEPHP_APPLE_ID' => 'nativephp-internal.notarization.apple_id',
        'NATIVEPHP_APPLE_ID_PASS' => 'nativephp-internal.notarization.apple_id_pass',
        'NATIVEPHP_APPLE_TEAM_ID' => 'nativephp-internal.notarization.apple_team_id',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->info('Not building on macOS, skipping entitlements patch.');

            return self::SUCCESS;
        }

        if ($this->option('adhoc') && $this->option('default')) {
            $this->error('The --adhoc and --default options cannot be combined.');

            return self::FAILURE;
        }

        $source = resource_path($this->usesDefaultEntitlements() ? self::DEFAULT_SOURCE : self::ADHOC_SOURCE);

        if (! File::exists($source)) {
            $this->error("Entitlements source is missing: {$source}");

            return self::FAILURE;
        }

        $destination = ElectronServiceProvider::electronPath(self::ENTITLEMENTS_PATH);

        if (! File::isDirectory(dirname($destination))) {
            $this->error("Electron build resources directory is missing: {$destination}");

            return self::FAILURE;
        }

        File::copy($source, $destination);

        $this->info("Patched entitlements: {$destination}");

        return self::SUCCESS;
    }

    /**
     * Determine whether to write NativePHP's default entitlements rather than the ad-hoc overrides,
     * reporting why so a wrong choice is visible in the build log.
     */
    private function usesDefaultEntitlements(): bool
    {
        if ($this->option('default')) {
            $this->info('Forcing NativePHP\'s default entitlements.');

            return true;
        }

        if ($this->option('adhoc')) {
            $this->info('Forcing the ad-hoc entitlements overrides.');

            return false;
        }

        $missing = $this->missingNotarizationKeys();

        if ($missing !== []) {
            $this->info('No notarization credentials (missing '.implode(', ', $missing).'), using the ad-hoc entitlements overrides.');

            return false;
        }

        $this->info('Notarization credentials found, using NativePHP\'s default entitlements.');
        $this->warn('Install a "Developer ID Application" certificate (or set CSC_LINK) before building: an ad-hoc signed build carrying these entitlements fails library validation at launch.');

        return true;
    }

    /**
     * List the environment variables among the notarization credentials that hold no value.
     *
     * @return array<int, string>
     */
    private function missingNotarizationKeys(): array
    {
        $missing = [];

        foreach (self::NOTARIZATION_KEYS as $variable => $configKey) {
            if (blank(config($configKey))) {
                $missing[] = $variable;
            }
        }

        return $missing;
    }
}
