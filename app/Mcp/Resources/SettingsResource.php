<?php

namespace App\Mcp\Resources;

use App\Concerns\Mcp\RespondsWithJson;
use App\Services\SettingsService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('settings')]
#[Title('Settings')]
#[Description('The current settings configuration.')]
#[Uri('laborforest://settings')]
#[MimeType('application/json')]
class SettingsResource extends Resource
{
    use RespondsWithJson;

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $settings = rescue(fn () => app(SettingsService::class)->loadSettings());

        if (! $settings) {
            return Response::error('Failed to load settings.');
        }

        return $this->json($settings->toMcpResource());
    }
}
