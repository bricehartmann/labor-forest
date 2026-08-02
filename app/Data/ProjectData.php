<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class ProjectData extends Data
{
    public function __construct(
        public string $uuid,
        public string $path,
    ) {}

    public function dirName(): string
    {
        return str($this->path)->afterLast(DIRECTORY_SEPARATOR)->toString();
    }

    public static function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'path' => ['required', 'string'],
        ];
    }
}
