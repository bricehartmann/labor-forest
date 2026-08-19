<?php

namespace App\Mcp\Resources;

use App\Services\SettingsService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Resource;

#[Name('settings')]
#[Title('Settings')]
#[Description('The current settings configuration as JSON.')]
class SettingsResource extends Resource
{
    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $settings = rescue(fn () => app(SettingsService::class)->loadSettings());

        if (! $settings) {
            return Response::error('Failed to load settings.');
        }

        return Response::structured($settings->toArray());
    }
}
