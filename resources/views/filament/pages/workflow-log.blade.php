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

        @if($this->workflowRunLogData->parent)
            <div class="flex items-center gap-2 -mt-4">
                <x-filament::icon class="text-gray-500" :icon="\Filament\Support\Icons\Heroicon::ArrowUturnUp"/>
                <div>
                    Started by
                    @if($this->parentRunLogData)
                        <x-filament::link :href="\App\Filament\Pages\WorkflowLog::getUrl(['uuid' => $this->projectData->uuid, 'slug' => $this->workspaceData->slugKebab(), 'id' => $this->parentRunLogData->id])">
                            {{ ucwords($this->parentRunLogData->name) }}
                        </x-filament::link>
                    @else
                        <code>{{ $this->workflowRunLogData->parent }}</code>
                    @endif
                </div>
            </div>
        @endif

        <div
            x-data="{ lastStepHash: null }"
            x-on:scroll-to-step.window="
                const stepHash = $event.detail.stepHash;

                if (! stepHash || stepHash === lastStepHash) return;

                lastStepHash = stepHash;

                requestAnimationFrame(() => $el.querySelector(`#step-${stepHash}`)
                    ?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
            "
            x-on:scroll-to-top.window="
                lastStepHash = null;

                requestAnimationFrame(() => requestAnimationFrame(() =>
                    window.scrollTo({ top: 0, behavior: 'smooth' })));
            "
            class="flex flex-col gap-6"
        >
            @foreach($this->workflowRunLogData->steps as $index => $step)
                <div id="step-{{ $step->hash ?? $index }}" wire:key="step-wrapper-{{ $step->hash ?? $index }}">
                    <livewire:workflow-log-step
                        wire:key="step-{{ $step->hash ?? $index }}"
                        :step="$step->toArray()"
                        :uuid="$this->projectData->uuid"
                        :slug="$this->workspaceData->slugKebab()"
                    />
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
