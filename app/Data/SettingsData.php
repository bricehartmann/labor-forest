<?php

namespace App\Data;

use App\Rules\ValidVariables;
use Spatie\LaravelData\Data;

class SettingsData extends Data
{
    public function __construct(
        public bool $dark_mode = true,
        public bool $desktop_notifications = true,
        public ?string $command_launch_ide = null,
        public ?string $command_launch_browser = null,
        public ?string $command_launch_terminal = null,
    ) {}

    public static function rules(): array
    {
        return [
            'dark_mode' => [
                'required',
                'boolean',
            ],
            'desktop_notifications' => [
                'required',
                'boolean',
            ],
            'command_launch_ide' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
            'command_launch_browser' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
            'command_launch_terminal' => [
                'nullable',
                'string',
                new ValidVariables,
            ],
        ];
    }
}
