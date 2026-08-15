<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Add CLI tools
        </x-slot>
        <x-slot name="description">
            Click the button below to select a directory and add the CLI tools script.
        </x-slot>
        <div class="flex justify-between">
            {{ $this->dismiss }}
            {{ $this->addCliTools }}
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
