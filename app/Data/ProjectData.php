<?php

namespace App\Data;

use App\Rules\ValidVariables;
use Spatie\LaravelData\Data;

class ProjectData extends Data
{
    public function __construct(
        public string $uuid,
        public string $path,
        public ?string $command_launch_ide = null,
        public ?string $command_launch_browser = null,
        public ?string $command_launch_terminal = null,
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
