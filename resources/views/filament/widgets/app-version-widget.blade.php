<x-filament-widgets::widget>
    <x-filament::section class="h-full">
        <x-slot name="heading">
            App Version
        </x-slot>
        <x-slot name="description">
            Below is the currently installed application version.
        </x-slot>
        <div class="flex justify-end items-center text-xl gap-4">
            {{ $appVersion }}
            @if($this->isLatestVersion)
                <x-filament::badge color="success" :icon="\Filament\Support\Icons\Heroicon::Check">
                    latest version
                </x-filament::badge>
            @elseif($this->latestReleaseTag)
                {{ $this->upgrade }}
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
