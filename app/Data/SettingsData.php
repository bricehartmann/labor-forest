<?php

namespace App\Data;

use App\Rules\ValidVariables;
use Spatie\LaravelData\Data;

class SettingsData extends Data
{
    public function __construct(
        public ?string $command_open_ide = null,
        public ?string $command_open_browser = null,
        public ?string $command_open_terminal = null,
    ) {}

    public static function rules(): array
    {
        return [
            'command_open_ide' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
            'command_open_browser' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
            'command_open_terminal' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
        ];
    }
}
