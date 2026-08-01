<?php

namespace App\Data;

use App\Enums\WorkflowStepType;
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
        public ?string $from = null,
        public ?string $to = null,
        public ?array $map = null,
    ) {}

    public static function rules(): array
    {
        return [
            'from' => [
                'required_if:type,'.WorkflowStepType::COPY_FROM_PRIMARY_DIR->value,
            ],
            'to' => [
                'required_if:type,'.WorkflowStepType::COPY_FROM_PRIMARY_DIR->value,
            ],
            'run' => [
                'required_if:type,'.WorkflowStepType::SHELL->value,
                'required_if:type,'.WorkflowStepType::WORKFLOW->value,
            ],
            'map' => [
                'required_if:type,'.WorkflowStepType::UPDATE_ENV->value,
            ],
        ];
    }
}
