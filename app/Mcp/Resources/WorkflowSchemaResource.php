<?php

namespace App\Mcp\Resources;

use App\Concerns\Mcp\RespondsWithJson;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\McpUri;
use App\Enums\Variable;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Enums\YamlResourceType;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('workflow-schema')]
#[Title('Workflow Schema')]
#[Description('The grammar of a workflow file: where it lives, every key it may carry, the three step types, and how a step is executed. Read this before writing or editing a workflow, because no tool writes one for you and `validate-workflow` only judges a file that already exists.')]
#[Uri(McpUri::WORKFLOW_SCHEMA->value)]
#[MimeType('application/json')]
class WorkflowSchemaResource extends Resource
{
    use RespondsWithJson;

    /**
     * Handle the resource request.
     *
     * Assembled from the enums and rules the app itself validates against rather than written out
     * as a literal, so a schema key cannot describe a grammar the app has since stopped accepting.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->json([
            'file' => $this->file(),
            'workflow_keys' => $this->workflowKeys(),
            'step_keys' => $this->stepKeys(),
            'step_types' => $this->stepTypes(),
            'variables' => $this->variables(),
            'execution' => $this->execution(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function file(): array
    {
        return [
            'directory' => Directory::BASE->value.'/'.Directory::WORKFLOWS->value,
            'extensions' => array_map(fn (FileExtension $extension) => $extension->value, FileExtension::cases()),
            'preferred_extension' => FileExtension::YAML->value,
            'notes' => [
                'The workflow is named by its file name without the extension, so `up.yaml` is the workflow `up`.',
                'Name files in kebab-case. A run log id slugifies the workflow name, so `db_refresh` and `db-refresh` would produce colliding log ids.',
                'A workspace holding both spellings of one name keeps the '.FileExtension::YAML->value.' file and ignores the other.',
                'The file must carry `resource_type: '.YamlResourceType::WORKFLOW->value.'` or it is not read as a workflow at all.',
                'A file that does declare that resource_type but fails validation hides every workflow of the workspace until it is fixed.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowKeys(): array
    {
        $declarable = WorkspaceStatus::declarableInWorkflowValues();

        return [
            'resource_type' => [
                'required' => true,
                'value' => YamlResourceType::WORKFLOW->value,
            ],
            'require_status' => [
                'required' => false,
                'enum' => $declarable,
                'description' => 'The status the workspace must already hold for this workflow to be allowed to start. Omit it to let the workflow run from any status that runs workflows at all.',
            ],
            'ending_status' => [
                'required' => false,
                'enum' => $declarable,
                'description' => 'The status the workspace is left in when every step succeeds. Omit it to return the workspace to the status it held before the run.',
            ],
            'sort_order' => [
                'required' => true,
                'type' => 'integer',
                'description' => 'Orders the workflow in the app menu. Ties break alphabetically, and negatives are allowed.',
            ],
            'steps' => [
                'required' => true,
                'type' => 'list',
                'description' => 'The steps, run in the order written. A workflow declaring no steps is hidden from the menu.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepKeys(): array
    {
        return [
            'name' => [
                'required' => true,
                'type' => 'string',
                'expands_variables' => false,
                'description' => 'What the step is called in the app and in the run log.',
            ],
            'type' => [
                'required' => true,
                'enum' => array_map(fn (WorkflowStepType $type) => $type->value, WorkflowStepType::cases()),
            ],
            'run' => [
                'required_for' => $this->typesRequiring('run'),
                'type' => 'string',
                'expands_variables' => true,
                'description' => 'A shell command for a `'.WorkflowStepType::SHELL->value.'` step, or the name of another workflow for a `'.WorkflowStepType::WORKFLOW->value.'` step.',
            ],
            'map' => [
                'required_for' => $this->typesRequiring('map'),
                'type' => 'mapping',
                'expands_variables' => 'values only, not keys',
                'description' => 'The .env keys to write and the values to write into them.',
            ],
            'if' => [
                'required' => false,
                'type' => 'string',
                'expands_variables' => true,
                'description' => 'A shell command gating the step: the step runs only when this exits zero.',
            ],
            'unless' => [
                'required' => false,
                'type' => 'string',
                'expands_variables' => true,
                'description' => 'A shell command gating the step: the step is skipped when this exits zero.',
            ],
            'env' => [
                'required' => false,
                'type' => 'mapping',
                'expands_variables' => 'values only, not keys',
                'description' => 'Environment variables set for this step alone.',
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function stepTypes(): array
    {
        return collect(WorkflowStepType::cases())
            ->map(fn (WorkflowStepType $type) => [
                'type' => $type->value,
                'requires' => $type->requiredKey(),
                'description' => $type->description(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(): array
    {
        return [
            'syntax' => '{{ NAME }}',
            'named' => collect(Variable::cases())
                ->map(fn (Variable $variable) => [
                    'variable' => $variable->value,
                    'example' => $variable->example(),
                ])
                ->all(),
            'environment_passthrough' => [
                'pattern' => 'ENV_<KEY>',
                'example' => '{{ ENV_APP_URL }}',
                'description' => 'Reads <KEY> from the workspace\'s own .env, re-read for each step so a step can use what an earlier `'.WorkflowStepType::UPDATE_ENV->value.'` step wrote. A name matching this pattern is always accepted by validation; a key missing from the .env fails the run instead.',
            ],
            'notes' => [
                'A `{{ }}` naming anything else is a validation failure, so a workflow cannot invent variables.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function execution(): array
    {
        return [
            'working_directory' => 'the workspace directory',
            'shell_wrapping' => 'A `'.WorkflowStepType::SHELL->value.'` step\'s `run` is wrapped in `set -eu; set -o pipefail` so a failure mid-chain surfaces instead of being swallowed.',
            'gates' => 'An `if` or `unless` command is not wrapped. Only its exit code is read, and a gate that fails never fails the run.',
            'environment' => 'LaborForest\'s own environment is stripped from every process, so a step inherits the user\'s shell environment rather than the app\'s.',
            'timeout' => 'The workflow step timeout from settings applies to each spawned process, gates included, rather than to the run as a whole.',
            'failure' => 'The first failing step ends the run. Steps after it are logged as skipped, and the workspace is left in `'.WorkspaceStatus::ERROR->value.'`.',
            'nested_workflows' => 'A `'.WorkflowStepType::WORKFLOW->value.'` step runs its child inline: the child skips the status gate, always runs all of its own steps, writes its own run log, and fails the parent with it. A workflow cannot appear twice in one chain.',
        ];
    }

    /**
     * The step types that cannot be written without the given key.
     *
     * @return array<int, string>
     */
    private function typesRequiring(string $key): array
    {
        return collect(WorkflowStepType::cases())
            ->filter(fn (WorkflowStepType $type) => $type->requiredKey() === $key)
            ->map(fn (WorkflowStepType $type) => $type->value)
            ->values()
            ->all();
    }
}
