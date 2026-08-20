<?php

namespace App\Mcp\Tools;

use App\Concerns\Mcp\RegistersWhenWritable;
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
    use RegistersWhenWritable;

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
                $settings->command_launch_ide = $this->commandOrNull($request->get('command_launch_ide'));
            }

            if ($request->get('command_launch_browser') !== null) {
                $settings->command_launch_browser = $this->commandOrNull($request->get('command_launch_browser'));
            }

            if ($request->get('command_launch_terminal') !== null) {
                $settings->command_launch_terminal = $this->commandOrNull($request->get('command_launch_terminal'));
            }

            $settingsService->saveSettings($settings);
        } catch (Throwable $th) {
            return Response::error($th->getMessage());
        }

        broadcast(new GlobalRefresh);

        return Response::text('success')->asAssistant();
    }

    /**
     * Normalize a cleared command to null.
     *
     * LaunchService treats a falsy command as nothing to launch, so an empty string already stops
     * the launch. Storing null instead keeps the settings file saying what it means, and matches
     * what the Settings screen writes for a blank field.
     */
    protected function commandOrNull(string $command): ?string
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
            'dark_mode' => $schema
                ->boolean()
                ->description('If dark mode is enabled. Omit or specify `null` to keep the current value.')
                ->nullable(),
            'workflow_step_timeout_seconds' => $schema
                ->integer()
                ->min(0)
                ->description('Workflow step timeout in seconds. Omit or specify `null` to keep the current value.')
                ->nullable(),
            'command_launch_ide' => $schema
                ->string()
                ->description('The global command to launch an IDE. Supports template variables in mustache syntax. See the `template-variables` resource. Omit or specify `null` to keep the current value, or specify an empty string to clear the command.')
                ->nullable(),
            'command_launch_browser' => $schema
                ->string()
                ->description('The global command to launch a web browser. Supports template variables in mustache syntax. See the `template-variables` resource. Omit or specify `null` to keep the current value, or specify an empty string to clear the command.')
                ->nullable(),
            'command_launch_terminal' => $schema
                ->string()
                ->description('The global command to launch a terminal. Supports template variables in mustache syntax. See the `template-variables` resource. Omit or specify `null` to keep the current value, or specify an empty string to clear the command.')
                ->nullable(),
        ];
    }
}
