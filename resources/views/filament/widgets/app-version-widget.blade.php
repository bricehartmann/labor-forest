<x-filament-widgets::widget>
    <x-filament::section class="h-full">
        <x-slot name="heading">
            App Version
        </x-slot>
        <x-slot name="description">
            Below is the currently installed application version.
        </x-slot>
        <div class="flex justify-end text-xl">
            {{ $appVersion }}
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
