<x-filament-panels::page>
    @if($this->loadedInvalidMessage)
        <x-filament::callout
            icon="heroicon-o-exclamation-circle"
            color="danger"
            class="mb-6"
        >
            <x-slot name="heading">
                Invalid Settings File
            </x-slot>

            <x-slot name="description">
                {{ $this->loadedInvalidMessage }}

                <div class="font-bold text-sm">
                    Saving this page will overwrite your settings file.
                </div>
            </x-slot>
        </x-filament::callout>
    @endif

    <form wire:submit="save">
        <div class="mb-6 flex justify-end">
            <x-filament::button color="primary" type="submit">
                Save changes
            </x-filament::button>
        </div>

        {{ $this->form }}
    </form>
</x-filament-panels::page>
