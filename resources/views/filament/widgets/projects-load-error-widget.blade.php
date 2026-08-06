<x-filament-widgets::widget>
    <x-filament::callout
        icon="heroicon-o-exclamation-circle"
        color="danger"
    >
        <x-slot name="heading">
            Issue Loading Projects
        </x-slot>

        <x-slot name="description">
            {{ $this->loadedInvalidMessage }}
        </x-slot>
    </x-filament::callout>
</x-filament-widgets::widget>
