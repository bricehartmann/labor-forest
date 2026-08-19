<?php

namespace App\Data;

use App\Contracts\McpResource;
use App\Enums\WorkflowStepType;
use App\Rules\ValidVariables;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class WorkflowStepData extends Data implements McpResource
{
    public function __construct(
        public string $name,
        #[WithCast(EnumCast::class)]
        public WorkflowStepType $type,
        public ?array $env = null,
        public ?string $if = null,
        public ?string $unless = null,
        public ?string $run = null,
        public ?array $map = null,
    ) {}

    public function hash(string $index): string
    {
        $array = [
            'index' => $index,
            ...$this->toArray(),
        ];
        ksort($array);

        return md5(json_encode($array));
    }

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
            'if' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
            'unless' => [
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

    /**
     * Omit null properties so generated YAML only contains meaningful keys.
     *
     * Overrides transform() rather than toArray() because spatie/laravel-data
     * serializes nested data objects via transform(), bypassing toArray().
     */
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return array_filter(
            parent::transform($transformationContext),
            fn ($value) => $value !== null,
        );
    }

    public function toMcpResource(): array
    {
        return $this->toArray();
    }
}
