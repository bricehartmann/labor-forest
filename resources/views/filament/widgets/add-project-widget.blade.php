<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Add project
        </x-slot>
        <x-slot name="description">
            Click the button below to select a directory and add it as a project.
        </x-slot>
        <div class="flex justify-center">
            {{ $this->addProject }}
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
