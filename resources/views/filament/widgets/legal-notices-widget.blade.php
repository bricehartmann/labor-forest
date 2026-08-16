<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            License
        </x-slot>
        <x-slot name="description">
            Copyright (C) 2026 Brice Hartmann
        </x-slot>
        <div class="space-y-2 text-sm text-gray-500 dark:text-gray-400">
            <p>
                LaborForest is free software: you are welcome to redistribute it and/or modify it under
                the terms of the GNU General Public License as published by the Free Software Foundation,
                either version 3 of the License, or (at your option) any later version.
            </p>
            <p>
                This program comes with ABSOLUTELY NO WARRANTY; without even the implied warranty of
                MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
            </p>
        </div>
        <div class="flex justify-end mt-4">
            {{ $this->viewLicense }}
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
