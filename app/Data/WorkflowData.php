<?php

namespace App\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class WorkflowData extends Data
{
    public function __construct(
        public ?int $sort_order,
        /** @var Collection<int, WorkflowStepData> */
        public Collection $steps,
    ) {}

}
