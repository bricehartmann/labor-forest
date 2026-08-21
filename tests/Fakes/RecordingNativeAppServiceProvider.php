<?php

namespace Tests\Fakes;

use App\Providers\NativeAppServiceProvider;
use Native\Desktop\Windows\Window as NativeWindow;

/**
 * A NativeAppServiceProvider that records where boot() sends the window instead of telling the
 * Electron runtime, which does not exist under test.
 */
final class RecordingNativeAppServiceProvider extends NativeAppServiceProvider
{
    /**
     * Every URL the window was sent to, in order.
     *
     * @var array<int, string>
     */
    public array $navigations = [];

    protected function navigateTo(NativeWindow $window, string $url): void
    {
        $this->navigations[] = $url;
    }
}
