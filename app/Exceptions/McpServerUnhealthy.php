<?php

namespace App\Exceptions;

use App\Enums\McpServerStatus;
use Exception;

class McpServerUnhealthy extends Exception
{
    public function __construct(
        public readonly McpServerStatus $status,
        public readonly string $url,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($status->message($url, $httpStatus));
    }
}
