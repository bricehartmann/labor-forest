<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button color="primary" type="submit">
                Save
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
