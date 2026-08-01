<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class ProjectData extends Data
{
    public function __construct(
        public string $path,
    ) {}

    public function title(): string
    {
        return str($this->path)->afterLast('/')->replace(['-', '_'], ' ')->ucWords()->toString();
    }
}
