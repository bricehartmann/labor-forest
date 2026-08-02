<x-filament-panels::page>
    @if($this->loadedInvalidMessage)
        <x-filament::callout
            icon="heroicon-o-exclamation-circle"
            color="danger"
            class="mb-6"
        >
            <x-slot name="heading">
                Issue Loading Project
            </x-slot>

            <x-slot name="description">
                {{ $this->loadedInvalidMessage }}
            </x-slot>
        </x-filament::callout>
    @endif

    @dump($this->project)
</x-filament-panels::page>
