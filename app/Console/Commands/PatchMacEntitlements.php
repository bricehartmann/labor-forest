<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Native\Desktop\Drivers\Electron\ElectronServiceProvider;

#[Signature('app:patch-mac-entitlements')]
#[Description('Overwrite the NativePHP Electron entitlements with the project copy so ad-hoc signed macOS builds pass library validation')]
class PatchMacEntitlements extends Command
{
    private const ENTITLEMENTS_PATH = 'build/entitlements.mac.plist';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->info('Not building on macOS, skipping entitlements patch.');

            return self::SUCCESS;
        }

        $source = resource_path('nativephp/entitlements.mac.plist');

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
}
