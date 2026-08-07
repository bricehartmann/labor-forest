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
        <div class="flex items-center gap-2">
            <x-filament::icon class="mt-1 text-{{ $this->workflowRunLogData->status->getColor() }}-500" :size="\Filament\Support\Enums\IconSize::Large" :icon="$this->workflowRunLogData->status->getIcon()"/>
            <div class="text-3xl">{{ $this->workflowRunLogData->status->value }}</div>
        </div>

        @foreach($this->workflowRunLogData->steps as $index => $step)
            <livewire:workflow-log-step wire:key="step-{{ $step->hash ?? $index }}" :step="$step->toArray()"/>
        @endforeach
    @endif
</x-filament-panels::page>
