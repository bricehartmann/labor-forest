<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Read the Docs
        </x-slot>
        <x-slot name="description">
            Click the button below to open a web browser and view the documentation.
        </x-slot>
        <div class="flex justify-end">
            {{ $this->readDocs }}
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
