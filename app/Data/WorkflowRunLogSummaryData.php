<?php

namespace App\Data;

use App\Contracts\McpResource;
use App\Enums\WorkflowStatus;
use App\Enums\YamlResourceType;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

/**
 * A run log without its steps, for listing runs.
 *
 * Step output is streamed into WorkflowRunLogData::$steps by RunWorkflow and can run to
 * megabytes per run, so a list of runs is hydrated from this instead. Unknown keys are ignored
 * by Data::from(), which lets a whole parsed log hydrate this just as well as a stripped header.
 */
class WorkflowRunLogSummaryData extends Data implements McpResource
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
    ) {}

    public function transform(null|TransformationContextFactory|TransformationContext $transformationContext = null): array
    {
        return [
            'resource_type' => YamlResourceType::WORKFLOW_RUN_LOG->value,
            ...parent::transform($transformationContext),
        ];
    }

    public function toMcpResource(): array
    {
        return $this->toArray();
    }
}
