<?php

namespace App\Data;

use App\Contracts\McpResource;
use App\Rules\ValidVariables;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

class ProjectData extends Data implements McpResource
{
    public function __construct(
        public string $uuid,
        public string $path,
        public int $last_opened,
        public ?string $command_launch_ide = null,
        public ?string $command_launch_browser = null,
        public ?string $command_launch_terminal = null,
    ) {}

    public function dirName(): string
    {
        return str($this->path)->afterLast(DIRECTORY_SEPARATOR)->toString();
    }

    public function parentDir(): string
    {
        return dirname($this->path);
    }

    public function slugKebab(): string
    {
        return Str::slug($this->dirName());
    }

    public function slugSnake(): string
    {
        return Str::slug($this->dirName(), '_');
    }

    public static function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'path' => ['required', 'string'],
            'last_opened' => ['required', 'integer'],
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

    public function toMcpResource(): array
    {
        return [
            ...$this->toArray(),
            'dir_name' => $this->dirName(),
            'parent_dir' => $this->parentDir(),
            'slug_kebab' => $this->slugKebab(),
            'slug_snake' => $this->slugSnake(),
        ];
    }
}
