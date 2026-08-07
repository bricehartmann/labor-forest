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

        <div class="mt-4 flex flex-col gap-4">
            <table class="w-full border-collapse border border-slate-400">
                <tbody>
                    @if($this->stepData->exitCode !== null)
                    <tr>
                        <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">EXIT CODE</td>
                        <td class="border border-slate-300 px-3 py-2 w-full"><code>{{ $this->stepData->exitCode }}</code></td>
                    </tr>
                    @endif
                    @if($this->stepData->skip_reason)
                    <tr>
                        <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">SKIP REASON</td>
                        <td class="border border-slate-300 px-3 py-2 w-full">{{ $this->stepData->skip_reason->getLabel() }}</td>
                    </tr>
                    @endif
                    @if($this->stepData->if)
                    <tr>
                        <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">IF CONDITION</td>
                        <td class="border border-slate-300 px-3 py-2 w-full"><code>{{ $this->stepData->if }}</code></td>
                    </tr>
                    @endif
                    @if($this->stepData->unless)
                    <tr>
                        <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">UNLESS CONDITION</td>
                        <td class="border border-slate-300 px-3 py-2 w-full"><code>{{ $this->stepData->unless }}</code></td>
                    </tr>
                    @endif
                    @if($this->stepData->run !== null)
                     <tr>
                         <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">RUN</td>
                         <td class="border border-slate-300 px-3 py-2 w-full"><code>{{ $this->stepData->run }}</code></td>
                     </tr>
                    @endif
                    @if($this->stepData->log_id !== null)
                     <tr>
                         <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">CHILD RUN</td>
                         <td class="border border-slate-300 px-3 py-2 w-full">
                             <x-filament::link :href="\App\Filament\Pages\WorkflowLog::getUrl(['uuid' => $this->uuid, 'slug' => $this->slug, 'id' => $this->stepData->log_id])">
                                 <code>{{ $this->stepData->log_id }}</code>
                             </x-filament::link>
                         </td>
                     </tr>
                    @endif
                    @if($this->stepData->env !== null)
                     <tr>
                         <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">ENV</td>
                         <td class="border border-slate-300 px-3 py-2 w-full">
                             <table class="w-full border-collapse border border-slate-400">
                                 <tbody>
                                    @foreach ($this->stepData->env as $key => $value)
                                        <tr>
                                            <td class="border border-slate-300 px-3 py-2 whitespace-nowrap"><code>{{ $key }}</code></td>
                                            <td class="border border-slate-300 px-3 py-2 w-full"><code>{{ $value }}</code></td>
                                        </tr>
                                    @endforeach
                                 </tbody>
                             </table>
                         </td>
                     </tr>
                    @endif
                    @if($this->stepData->map !== null)
                     <tr>
                         <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">MAP</td>
                         <td class="border border-slate-300 px-3 py-2 w-full">
                             <table class="w-full border-collapse border border-slate-400">
                                 <tbody>
                                    @foreach ($this->stepData->map as $key => $value)
                                        <tr>
                                            <td class="border border-slate-300 px-3 py-2 whitespace-nowrap"><code>{{ $key }}</code></td>
                                            <td class="border border-slate-300 px-3 py-2 w-full"><code>{{ $value }}</code></td>
                                        </tr>
                                    @endforeach
                                 </tbody>
                             </table>
                         </td>
                     </tr>
                    @endif
                    @if($this->stepData->output !== null)
                        <tr>
                            <td class="border border-slate-300 px-3 py-2 whitespace-nowrap">OUTPUT</td>
                            <td class="border border-slate-300 px-3 py-2 w-full bg-black"><code>{!! nl2br($this->stepData->outputHtml()) !!}</code></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
