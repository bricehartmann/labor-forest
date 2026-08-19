<?php

namespace App\Data;

use App\Enums\McpServerStatus;
use Spatie\LaravelData\Data;

class McpServerHealthData extends Data
{
    public function __construct(
        public McpServerStatus $status,
        public string $url,
        public string $server_name,
        public string $server_version,
        public string $protocol_version,
    ) {}

    /**
     * The handshake in one sentence, for a notification body.
     */
    public function description(): string
    {
        return "{$this->server_name} {$this->server_version} answered at {$this->url} over MCP {$this->protocol_version}.";
    }
}
