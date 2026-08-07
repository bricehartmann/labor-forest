<?php

namespace App\Livewire;

use App\Data\WorkflowRunLogStepData;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class WorkflowLogStep extends Component
{
    #[Reactive]
    public array $step = [];

    /**
     * The route context of the log page this step is rendered on, so a step that started another
     * workflow can link to that workflow's log.
     */
    #[Locked]
    public string $uuid = '';

    #[Locked]
    public string $slug = '';

    #[Computed]
    public function stepData(): ?WorkflowRunLogStepData
    {
        return $this->step ? WorkflowRunLogStepData::from($this->step) : null;
    }

    public function render(): View
    {
        return view('livewire.workflow-log-step');
    }
}
