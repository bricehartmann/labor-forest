<?php

use App\Events\GlobalRefresh;
use App\Livewire\RefreshButton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('user_home');
});

describe('flushCache', function () {
    it('empties the cache', function () {
        Cache::put('anything', 'from before the refresh');

        Livewire::test(RefreshButton::class)
            ->call('flushCache')
            ->assertOk();

        expect(Cache::has('anything'))->toBeFalse();
    });
});

describe('globalRefresh', function () {
    it('empties the cache and reloads the page when the broadcast arrives', function () {
        Cache::put('anything', 'from before the refresh');

        Livewire::test(RefreshButton::class)
            ->dispatch('native:'.GlobalRefresh::class)
            ->assertOk()
            ->assertJs('window.location.reload()');

        expect(Cache::has('anything'))->toBeFalse();
    });
});

describe('rendering', function () {
    it('reloads the page once the flush has answered', function () {
        Livewire::test(RefreshButton::class)
            ->assertOk()
            ->assertSee('x-on:click="$wire.flushCache().then(() => window.location.reload())"', escape: false);
    });

    it('rides along on a panel page', function () {
        // The dashboard renders AppVersionWidget, which asks GitHub for the latest release.
        Http::fake([config('app.latest_release_url') => Http::response([
            'html_url' => 'https://example.test/releases/v1.2.3',
            'tag_name' => 'v1.2.3',
        ])]);

        $this->get('/')
            ->assertOk()
            ->assertSee('flushCache', escape: false);
    });
});
