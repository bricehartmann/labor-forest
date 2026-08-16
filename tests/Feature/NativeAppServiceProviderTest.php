<?php

use App\Enums\WindowId;
use App\Providers\NativeAppServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as NativeWindow;

beforeEach(function () {
    Storage::fake('user_home');

    $this->windows = Window::fake()
        ->alwaysReturnWindows([new NativeWindow(WindowId::MAIN->value)]);
});

describe('boot', function () {
    it('empties the cache', function () {
        Cache::put('anything', 'from a previous launch');

        (new NativeAppServiceProvider)->boot();

        expect(Cache::has('anything'))->toBeFalse();
    });

    it('still opens the main window', function () {
        (new NativeAppServiceProvider)->boot();

        expect($this->windows->opened)->toBe([WindowId::MAIN->value]);
    });
});
