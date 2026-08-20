<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\ResolvesProject;
use App\Events\GlobalRefresh;
use App\Rules\ValidVariables;
use App\Services\ProjectsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[IsDestructive]
#[Name('update-project-launch-commands')]
#[Title('Update Project Launch Commands')]
#[Description('Update the launch command overrides of a single project, which take precedence over the global launch commands.')]
class UpdateProjectLaunchCommandsTool extends Tool
{
    use ResolvesProject;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'path' => 'required_without:uuid',
            'uuid' => 'required_without:path',
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
        ]);

        $project = $this->resolveProject($validated['path'] ?? null, $validated['uuid'] ?? null);

        if ($project instanceof Response) {
            return $project;
        }

        if ($request->get('command_launch_ide') !== null) {
            $project->command_launch_ide = $this->overrideOrNull($request->get('command_launch_ide'));
        }

        if ($request->get('command_launch_browser') !== null) {
            $project->command_launch_browser = $this->overrideOrNull($request->get('command_launch_browser'));
        }

        if ($request->get('command_launch_terminal') !== null) {
            $project->command_launch_terminal = $this->overrideOrNull($request->get('command_launch_terminal'));
        }

        try {
            app(ProjectsService::class)->updateProject($project);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        broadcast(new GlobalRefresh);

        return Response::text('success')->asAssistant();
    }

    /**
     * Normalize a cleared override to null.
     *
     * LaunchService falls back to the global command on a falsy override, so an empty string would
     * already stop that project launching anything. Storing null instead keeps the projects file
     * saying what it means, and matches what the Project screen writes for a blank field.
     */
    protected function overrideOrNull(string $command): ?string
    {
        return filled($command) ? $command : null;
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
                ->description('The full directory path to a project. Required without `uuid`.')
                ->nullable(),
            'uuid' => $schema
                ->string()
                ->description('The project UUID. Required without `path`.')
                ->nullable(),
            'command_launch_ide' => $schema
                ->string()
                ->description('The command this project launches an IDE with, overriding the global one. Supports template variables in mustache syntax. See the `template-variables` resource. Omit or specify `null` to keep the current value, or specify an empty string to clear the override and use the global command.')
                ->nullable(),
            'command_launch_browser' => $schema
                ->string()
                ->description('The command this project launches a web browser with, overriding the global one. Supports template variables in mustache syntax. See the `template-variables` resource. Omit or specify `null` to keep the current value, or specify an empty string to clear the override and use the global command.')
                ->nullable(),
            'command_launch_terminal' => $schema
                ->string()
                ->description('The command this project launches a terminal with, overriding the global one. Supports template variables in mustache syntax. See the `template-variables` resource. Omit or specify `null` to keep the current value, or specify an empty string to clear the override and use the global command.')
                ->nullable(),
        ];
    }
}
