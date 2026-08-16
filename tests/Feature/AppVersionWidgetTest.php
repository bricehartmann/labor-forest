<?php

use App\Filament\Widgets\AppVersionWidget;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('user_home');
});

describe('render', function () {
    it('renders the heading and description', function () {
        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('App Version')
            ->assertSee('Below is the currently installed application version.');
    });

    it('renders the configured application version', function () {
        config()->set('nativephp.version', 'v1.0.0-rc.2');

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('v1.0.0-rc.2');
    });

    it('still renders when no application version is configured', function () {
        config()->set('nativephp.version', null);

        Livewire::test(AppVersionWidget::class)
            ->assertOk()
            ->assertSee('App Version')
            ->assertDontSee('v1.0.0-rc.2');
    });
});
