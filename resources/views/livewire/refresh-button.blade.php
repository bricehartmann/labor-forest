{{-- The reload waits on the flush promise; reloading alongside the call would abort it mid-flight. --}}
<x-filament::button
    :icon="\Filament\Support\Icons\Heroicon::ArrowPath"
    color="gray"
    x-on:click="$wire.flushCache().then(() => window.location.reload())"
/>
