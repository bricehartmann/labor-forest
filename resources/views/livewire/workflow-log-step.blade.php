<div>
    <x-filament::section>
        <x-filament::section.heading>
            <div class="flex justify-between item-center">
                <div class="flex items-center gap-2">
                    <x-filament::icon class="text-{{ $this->stepData->getColor() }}-500" :icon="$this->stepData->getIcon()" />
                    {{ $this->stepData->name }}
                </div>
                <div>
                    {{ $this->stepData->time() }}
                </div>
            </div>
        </x-filament::section.heading>
    </x-filament::section>
</div>
