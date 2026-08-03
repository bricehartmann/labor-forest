<?php

namespace App\Data;

use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class GitStatusEntryData extends Data
{
    public function __construct(
        public string $code,
        public string $path,
    ) {}

    /**
     * Human readable description of the raw two character porcelain status code.
     */
    public function label(): string
    {
        return match (true) {
            $this->code === '??' => 'untracked',
            Str::contains($this->code, 'R') => 'renamed',
            Str::contains($this->code, 'D') => 'deleted',
            Str::contains($this->code, 'A') => 'added',
            Str::contains($this->code, 'M') => 'modified',
            default => trim($this->code),
        };
    }
}
