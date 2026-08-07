<?php

namespace App\Data;

use App\Enums\WorkflowStatus;
use App\Enums\YamlResourceType;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class WorkflowRunLogData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        /** The run log id of the workflow that started this one, when it was started by a workflow step. */
        public ?string $parent,
        public int $timestamp,
        #[WithCast(EnumCast::class)]
        public WorkflowStatus $status,
        public ?string $exception,
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

    /**
     * Fetch the log entry seeded for a step so a run can fill it in as the step progresses.
     *
     * @throws RuntimeException when the run log was not seeded with a step at this index
     */
    public function step(int|string $index): WorkflowRunLogStepData
    {
        $step = $this->steps->get($index);

        if (! $step instanceof WorkflowRunLogStepData) {
            throw new RuntimeException("Run log has no step at index [{$index}].");
        }

        return $step;
    }
}
