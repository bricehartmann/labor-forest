<?php

namespace App\Data;

use App\Enums\YamlResourceType;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class WorkflowData extends Data
{
    public function __construct(
        public int $sort_order,
        /** @var Collection<int, WorkflowStepData> */
        public Collection $steps,
    ) {}

    public function transform(null|TransformationContextFactory|TransformationContext $transformationContext = null): array
    {
        return [
            'resource_type' => YamlResourceType::WORKFLOW->value,
            ...parent::transform($transformationContext),
        ];
    }
}
