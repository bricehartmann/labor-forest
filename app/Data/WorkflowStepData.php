<?php

namespace App\Data;

use App\Enums\WorkflowStepType;
use App\Rules\ValidVariables;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;

class WorkflowStepData extends Data
{
    public function __construct(
        public string $name,
        #[WithCast(EnumCast::class)]
        public WorkflowStepType $type,
        public ?array $env = null,
        public ?string $condition = null,
        public ?string $run = null,
        public ?array $map = null,
    ) {}

    public static function rules(): array
    {
        return [
            'run' => [
                'required_if:type,'.WorkflowStepType::SHELL->value,
                'required_if:type,'.WorkflowStepType::WORKFLOW->value,
                'nullable',
                'string',
                new ValidVariables,
            ],
            'map' => [
                'required_if:type,'.WorkflowStepType::UPDATE_ENV->value,
                'nullable',
                'array',
            ],
            'condition' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
            'map.*' => [
                new ValidVariables,
            ],
            'env.*' => [
                new ValidVariables,
            ],
        ];
    }
}
