<?php

namespace App\Enums;

use InvalidArgumentException;
use Laravel\Mcp\Support\UriTemplate;

/**
 * Every URI the LaborForest MCP server answers on.
 *
 * The strings are named here rather than at their call sites, because a templated resource
 * registers its URI in one place and is addressed from several others — the resource link a tool
 * returns, and the `uri` a list resource hands back with each item. Those have to agree byte for
 * byte or the client reads nothing.
 */
enum McpUri: string
{
    case SETTINGS = 'laborforest://settings';
    case PROJECTS = 'laborforest://projects';
    case TEMPLATE_VARIABLES = 'laborforest://template-variables';
    case PROJECT = 'laborforest://projects/{uuid}';
    case WORKSPACES = 'laborforest://projects/{uuid}/workspaces';

    /**
     * The URI template a resource registers itself under.
     *
     * Only valid on the cases carrying a placeholder; UriTemplate rejects the fixed URIs itself.
     */
    public function template(): UriTemplate
    {
        return new UriTemplate($this->value);
    }

    /**
     * A concrete URI, with every placeholder filled.
     *
     * @param  array<string, string>  $variables
     *
     * @throws InvalidArgumentException when a placeholder is left unfilled
     */
    public function build(array $variables = []): string
    {
        $uri = str_replace(
            array_map(fn (string $key): string => '{'.$key.'}', array_keys($variables)),
            array_values($variables),
            $this->value,
        );

        if (str_contains($uri, '{')) {
            throw new InvalidArgumentException(sprintf('Unresolved placeholder in MCP URI [%s].', $uri));
        }

        return $uri;
    }
}
