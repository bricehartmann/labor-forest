<?php

namespace App\Data;

use App\Enums\WorkspaceStatus;
use App\Enums\YamlResourceType;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class WorkflowData extends Data
{
    public function __construct(
        #[WithCast(EnumCast::class)]
        public ?WorkspaceStatus $require_status,
        #[WithCast(EnumCast::class)]
        public ?WorkspaceStatus $ending_status,
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

    public static function rules(): array
    {
        return [
            'require_status' => [
                'nullable',
                'string',
                Rule::in(WorkspaceStatus::declarableInWorkflowValues()),
            ],
            'ending_status' => [
                'nullable',
                'string',
                Rule::in(WorkspaceStatus::declarableInWorkflowValues()),
            ],
        ];
    }

    /**
     * The hash of every step, in order — the selection meaning "run the whole workflow".
     *
     * @return array<int, string>
     */
    public function stepHashes(): array
    {
        return $this->steps
            ->values()
            ->map(fn (WorkflowStepData $step, int $index) => $step->hash((string) $index))
            ->all();
    }
}
