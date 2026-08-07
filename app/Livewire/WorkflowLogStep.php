<?php

namespace App\Livewire;

use App\Data\WorkflowRunLogStepData;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class WorkflowLogStep extends Component
{
    #[Reactive]
    public array $step = [];

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
