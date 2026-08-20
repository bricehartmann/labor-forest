<?php

namespace App\Mcp\Resources;

use App\Concerns\Mcp\RespondsWithJson;
use App\Enums\McpUri;
use App\Enums\Variable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('template-variables')]
#[Title('Template Variables')]
#[Description('The list of variables that can be used in a template in other tools, when denoted.')]
#[Uri(McpUri::TEMPLATE_VARIABLES->value)]
#[MimeType('application/json')]
class TemplateVariablesResource extends Resource
{
    use RespondsWithJson;

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $variables = collect(Variable::cases())
            ->map(fn (Variable $variable) => [
                'variable' => $variable->value,
                'example' => $variable->example(),
            ])
            ->all();

        return $this->json($variables);
    }
}
