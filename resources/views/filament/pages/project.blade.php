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
    @else
        <div class="flex justify-between gap-4">
            <div class="flex gap-4">
                {{ $this->editLaunchCommands }}
                {{ $this->remove }}
            </div>
            {{ $this->addWorkspaceAction }}
        </div>

        {{ $this->table }}
    @endif
</x-filament-panels::page>
