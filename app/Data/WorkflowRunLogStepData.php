<?php

namespace App\Data;

use App\Enums\WorkflowStepSkipReason;
use App\Enums\WorkflowStepStatus;
use App\Enums\WorkflowStepType;
use App\Rules\ValidVariables;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class WorkflowRunLogStepData extends Data
{
    public function __construct(
        public string $name,
        #[WithCast(EnumCast::class)]
        public WorkflowStepType $type,
        public ?int $exitCode,
        public string $output,
        #[WithCast(EnumCast::class)]
        public ?WorkflowStepSkipReason $skip_reason = null,
        public ?array $env = null,
        public ?string $if = null,
        public ?string $unless = null,
        public ?string $run = null,
        public ?array $map = null,
        public ?int $started_timestamp = null,
        public ?int $ended_timestamp = null,
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

    public function outputHtml(): ?HtmlString
    {
        if (! $this->output) {
            return null;
        }

        return new HtmlString((new AnsiToHtmlConverter)->convert($this->output));
    }

    public function status(): WorkflowStepStatus
    {
        return match (true) {
            $this->skip_reason !== null => WorkflowStepStatus::SKIPPED,
            $this->exitCode === 0 => WorkflowStepStatus::SUCCESS,
            $this->exitCode !== null => WorkflowStepStatus::FAILED,
            $this->started_timestamp !== null => WorkflowStepStatus::RUNNING,
            default => Heroicon::EllipsisHorizontalCircle,
        };
    }

    public function getColor(): string
    {
        return match ($this->status()) {
            WorkflowStepStatus::SUCCESS => 'success',
            WorkflowStepStatus::FAILED => 'danger',
            WorkflowStepStatus::RUNNING => 'warning',
            default => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this->status()) {
            WorkflowStepStatus::SKIPPED => Heroicon::MinusCircle,
            WorkflowStepStatus::SUCCESS => Heroicon::CheckCircle,
            WorkflowStepStatus::FAILED => Heroicon::XCircle,
            WorkflowStepStatus::RUNNING => Heroicon::PlayCircle,
            default => Heroicon::EllipsisHorizontalCircle,
        };
    }

    public function time(): ?string
    {
        if (! $this->started_timestamp) {
            return null;
        }

        if (! $this->ended_timestamp) {
            return Carbon::createFromTimestampUTC($this->started_timestamp)->shortAbsoluteDiffForHumans();
        }

        return Carbon::createFromTimestampUTC($this->ended_timestamp)->shortAbsoluteDiffForHumans(Carbon::createFromTimestampUTC($this->started_timestamp));
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
}
