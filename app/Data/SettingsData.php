<?php

namespace App\Data;

use App\Contracts\McpResource;
use App\Rules\ValidVariables;
use Illuminate\Support\Arr;
use Spatie\LaravelData\Data;

class SettingsData extends Data implements McpResource
{
    /**
     * Characters in a generated MCP bearer token.
     */
    public const int MCP_TOKEN_LENGTH = 64;

    public function __construct(
        public bool $dark_mode = true,
        public bool $cli_tools_installed = false,
        public int $workflow_step_timeout_seconds = 600,
        public bool $mcp_enabled = false,
        public int $mcp_port = 9189,
        public bool $mcp_read_only = true,
        public ?string $mcp_token = null,
        public ?string $command_launch_ide = null,
        public ?string $command_launch_browser = null,
        public ?string $command_launch_terminal = null,
    ) {}

    public static function defaults(): static
    {
        return new static(
            command_launch_ide: 'open "{{ WORKSPACE_DIR }}" -a phpstorm',
            command_launch_browser: 'open "{{ ENV_APP_URL }}"',
            command_launch_terminal: 'open "{{ WORKSPACE_DIR }}" -a iterm',
        );
    }

    public static function rules(): array
    {
        return [
            'dark_mode' => [
                'required',
                'boolean',
            ],
            'mcp_enabled' => [
                'required',
                'boolean',
            ],
            'cli_tools_installed' => [
                'required',
                'boolean',
            ],
            'workflow_step_timeout_seconds' => [
                'required',
                'integer',
                'min:0',
            ],
            'mcp_port' => [
                'required',
                'integer',
                'min:1024',
                'max:49151',
            ],
            'mcp_read_only' => [
                'required',
                'boolean',
            ],
            'mcp_token' => [
                'nullable',
                'string',
                'size:'.self::MCP_TOKEN_LENGTH,
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

    /**
     * The settings as an MCP client sees them.
     *
     * `mcp_token` is withheld. A client that reached this resource already holds the token, so
     * returning it buys nothing and only copies a credential into a transcript.
     */
    public function toMcpResource(): array
    {
        return Arr::except($this->toArray(), ['mcp_token']);
    }
}
