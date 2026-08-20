<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\ResolvesWorkspace;
use App\Services\LaunchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[Name('launch-browser')]
#[Title('Launch Browser')]
#[Description('Launch a browser for the given workspace path using the preconfigured command.')]
class LaunchBrowserTool extends Tool
{
    use ResolvesWorkspace;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $resolved = $this->resolveWorkspace(Str::rtrim($request->get('path'), '/'));

        if ($resolved instanceof Response) {
            return $resolved;
        }

        [$project, $workspace] = $resolved;

        try {
            app(LaunchService::class)->launchBrowser($project, $workspace);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        return Response::text('success')->asAssistant();
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema
                ->string()
                ->description('The full directory path to a workspace')
                ->required(),
        ];
    }
}
