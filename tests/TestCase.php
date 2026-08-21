<?php

namespace Tests;

use App\Enums\Disk;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application with the user_home disk already faked.
     *
     * That disk is registered by the NativePHP runtime, which no test boots, and the Filament panel
     * provider reads projects.yaml and settings.yaml while it *registers* — inside the bootstrap()
     * call below, before any beforeEach hook could fake anything. Without this every test boot
     * reported a missing driver, tens of megabytes of identical stack traces per suite run.
     *
     * The root is the one Storage::fake() uses, so the per-test fake in tests/Pest.php cleans this
     * very directory rather than shadowing it.
     *
     * The parent implementation is not reusable here: it bootstraps before it returns. Its only extra
     * behaviour is WithCachedConfig / WithCachedRoutes support, which no test in this suite uses.
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set('filesystems.disks.'.Disk::USER_HOME->value, [
                'driver' => 'local',
                'root' => $app->storagePath('framework/testing/disks/'.Disk::USER_HOME->value),
                'throw' => false,
            ]);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
