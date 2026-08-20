<?php

namespace App\Concerns\Mcp;

use Laravel\Mcp\Response;

/**
 * Shared JSON encoding for the resources, whose #[MimeType] promises application/json.
 */
trait RespondsWithJson
{
    /**
     * Slashes and unicode are left unescaped because the payloads carry filesystem paths a
     * client is expected to read back verbatim.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws \JsonException when the payload cannot be encoded
     */
    protected function json(array $payload): Response
    {
        return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
