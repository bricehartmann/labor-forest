<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Install CLI tools
        </x-slot>
        <x-slot name="description">
            Click the button below to select a directory and install the CLI tools script.
        </x-slot>
        <div class="flex justify-end gap-4">
            {{ $this->dismiss }}
            {{ $this->installCliTools }}
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
