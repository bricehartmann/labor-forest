<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class SettingsData extends Data
{
    public function __construct(
        public ?string $command_open_ide = null,
        public ?string $command_open_browser = null,
        public ?string $command_open_terminal = null,
    ) {}
}
