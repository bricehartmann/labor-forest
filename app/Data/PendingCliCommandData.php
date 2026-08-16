<?php

namespace App\Data;

use App\Enums\CliCommand;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;

/**
 * A request the `lf` script left in ~/.laborforest/pending.yaml for the app to pick up.
 */
class PendingCliCommandData extends Data
{
    public function __construct(
        #[WithCast(EnumCast::class)]
        public CliCommand $command,
        public string $path,
        public ?string $workflow = null,
    ) {}

    public static function rules(): array
    {
        return [
            'command' => [
                'required',
                'string',
                Rule::enum(CliCommand::class),
            ],
            'path' => [
                'required',
                'string',
            ],
            'workflow' => [
                'nullable',
                'string',
            ],
        ];
    }
}
