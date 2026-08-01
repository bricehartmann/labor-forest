<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class SettingsData extends Data
{
    public function __construct(
        public ?string $command_open_ide,
        public ?string $command_open_browser,
    ) {}
}
