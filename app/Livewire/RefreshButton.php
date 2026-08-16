<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The topbar reload button, rendered on every panel page through a render hook.
 *
 * It owns its own click because the hook renders inside whichever page component is showing, so a
 * bare wire:click would have to land on Dashboard, Project, Settings and every page after them.
 */
class RefreshButton extends Component
{
    /**
     * Empty the cache so the page that follows is not answered from the entry it is refreshing —
     * AppVersionWidget would otherwise keep reading the release it looked up up to 15 minutes ago.
     */
    public function flushCache(): void
    {
        Cache::flush();
    }

    public function render(): View
    {
        return view('livewire.refresh-button');
    }
}
