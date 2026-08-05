<?php

namespace App\Data;

use App\Enums\WorkflowStatus;
use App\Enums\YamlResourceType;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class WorkflowRunLogData extends Data
{
    public function __construct(
        public ?string $parent,
        public int $timestamp,
        #[WithCast(EnumCast::class)]
        public WorkflowStatus $status,
        /** @var Collection<int, WorkflowRunLogStepData> */
        public Collection $steps,
    ) {}

    public function transform(null|TransformationContextFactory|TransformationContext $transformationContext = null): array
    {
        return [
            'resource_type' => YamlResourceType::WORKFLOW_RUN_LOG->value,
            ...parent::transform($transformationContext),
        ];
    }

    public function appendToSteps(WorkflowRunLogStepData $step): void
    {
        $this->steps->push($step);
    }
}
