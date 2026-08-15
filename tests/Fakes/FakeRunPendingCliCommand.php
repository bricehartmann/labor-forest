<?php

namespace Tests\Fakes;

use App\Listeners\RunPendingCliCommand;

/**
 * A RunPendingCliCommand that records where it would send the window instead of talking to the
 * Electron runtime, which does not exist under test.
 */
final class FakeRunPendingCliCommand extends RunPendingCliCommand
{
    /**
     * Every URL handed to navigateTo(), in order.
     *
     * @var array<int, string>
     */
    public array $navigations = [];

    protected function navigateTo(string $url): void
    {
        $this->navigations[] = $url;
    }
}
