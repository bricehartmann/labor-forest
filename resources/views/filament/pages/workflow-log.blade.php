<x-filament-panels::page>
    @if($this->loadedInvalidMessage)
        <x-filament::callout
            icon="heroicon-o-exclamation-circle"
            color="danger"
            class="mb-6"
        >
            <x-slot name="heading">
                Issue Loading Data
            </x-slot>

            <x-slot name="description">
                {{ $this->loadedInvalidMessage }}
            </x-slot>
        </x-filament::callout>
    @else
        @foreach($this->workflowRunLogData->steps as $index => $step)
            <livewire:workflow-log-step wire:key="$index" :step="$step->toArray()" />
        @endforeach
    @endif
</x-filament-panels::page>
