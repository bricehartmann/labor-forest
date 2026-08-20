<?php

namespace App\Mcp\Tools;

use App\Events\GlobalRefresh;
use App\Rules\ValidVariables;
use App\Services\SettingsService;
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
#[Name('update-settings')]
#[Title('Update Settings')]
#[Description('Update global configuration settings.')]
class UpdateSettingsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $request->validate([
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

        $settingsService = app(SettingsService::class);

        try {
            $settings = $settingsService->loadSettings();

            if ($request->get('dark_mode') !== null) {
                $settings->dark_mode = $request->boolean('dark_mode');
            }

            if ($request->get('workflow_step_timeout_seconds') !== null) {
                $settings->workflow_step_timeout_seconds = $request->integer('workflow_step_timeout_seconds');
            }

            if ($request->get('command_launch_ide') !== null) {
                $settings->command_launch_ide = $request->get('command_launch_ide');
            }

            if ($request->get('command_launch_browser') !== null) {
                $settings->command_launch_ide = $request->get('command_launch_browser');
            }

            if ($request->get('command_launch_terminal') !== null) {
                $settings->command_launch_ide = $request->get('command_launch_terminal');
            }

            $settingsService->saveSettings($settings);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        broadcast(new GlobalRefresh);

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
            'dark_mode' => $schema
                ->boolean()
                ->nullable()
                ->description('If dark mode is enabled. Omit or specify null to keep current value.'),
            'workflow_step_timeout_seconds' => $schema
                ->integer()
                ->min(0)
                ->nullable()
                ->description('Workflow step timeout in seconds. Omit or specify null to keep current value.'),
            'command_launch_ide' => $schema
                ->string()
                ->nullable()
                ->description('The global command launch an IDE. Supports template variables in mustache syntax. See resource: template-variables'),
            'command_launch_browser' => $schema
                ->string()
                ->nullable()
                ->description('The global command launch a web browser. Supports template variables in mustache syntax. See resource: template-variables'),
            'command_launch_terminal' => $schema
                ->string()
                ->nullable()
                ->description('The global command launch a terminal. Supports template variables in mustache syntax. See resource: template-variables'),
        ];
    }
}
