<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

enum McpServerStatus: string
{
    case HEALTHY = 'healthy';
    case UNREACHABLE = 'unreachable';
    case FORBIDDEN = 'forbidden';
    case FOREIGN = 'foreign';
    case FAILED = 'failed';
    case APP_PORT = 'app_port';

    /**
     * The icon a notification carrying this status shows.
     */
    public function icon(): Heroicon
    {
        return match ($this) {
            self::HEALTHY => Heroicon::CheckCircle,
            self::UNREACHABLE => Heroicon::SignalSlash,
            self::FORBIDDEN => Heroicon::LockClosed,
            self::FOREIGN, self::APP_PORT => Heroicon::QuestionMarkCircle,
            self::FAILED => Heroicon::XCircle,
        };
    }

    /**
     * The one-line heading of the status.
     */
    public function title(): string
    {
        return match ($this) {
            self::HEALTHY => 'The MCP server answered',
            self::UNREACHABLE => 'Nothing is listening',
            self::FORBIDDEN => 'The endpoint refused the request',
            self::FOREIGN => 'Something else is on that port',
            self::FAILED => 'The endpoint answered with an error',
            self::APP_PORT => 'That port belongs to the app window',
        };
    }

    /**
     * What the status means for the endpoint that was probed.
     *
     * The wording matters more than usual here, because every one of these states reaches an MCP
     * client as either a missing server or a request to authenticate. FORBIDDEN especially: a client
     * cannot tell NativePHP's browser guard apart from a rejected token apart from a foreign server
     * that guards its own port.
     */
    public function message(string $url, ?int $httpStatus = null): string
    {
        return match ($this) {
            self::HEALTHY => "{$url} completed an MCP handshake.",
            self::UNREACHABLE => "Nothing answered at {$url}. Either the server is not running, or it is running on a different port.",
            self::FORBIDDEN => "{$url} answered {$httpStatus}. The bearer token was rejected, NativePHP's browser guard is still in front of the route, or another application owns that port. An MCP client reports this as a request to authenticate.",
            self::FOREIGN => "{$url} answered, but not with an MCP handshake. Another application is using that port.",
            self::FAILED => "{$url} answered {$httpStatus}.",
            self::APP_PORT => "{$url} is the port the app window itself is served on. Pick another port.",
        };
    }
}
