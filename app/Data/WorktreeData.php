<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class WorktreeData extends Data
{
    public function __construct(
        public string $path,
        public ?string $branch,
        public ?string $sha = null,
    ) {}
}
