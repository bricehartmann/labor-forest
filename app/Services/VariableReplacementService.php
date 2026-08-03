<?php

namespace App\Services;

use App\Data\ProjectData;
use App\Data\WorkspaceData;
use App\Enums\Variable;
use App\Exceptions\UnresolvedVariable;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

class VariableReplacementService
{
    /**
     * Expand every {{ VARIABLE }} placeholder within the given content.
     *
     * @throws UnresolvedVariable
     */
    public function replace(ProjectData $projectData, WorkspaceData $workspaceData, string $content): string
    {
        $replacers = [
            Variable::PROJECT_PRIMARY_DIR->value => $projectData->path,
            Variable::PROJECT_SLUG_KEBAB->value => $projectData->slugKebab(),
            Variable::PROJECT_SLUG_SNAKE->value => $projectData->slugSnake(),
            Variable::WORKSPACE_DIR->value => $workspaceData->path,
            Variable::WORKSPACE_SLUG_KEBAB->value => $workspaceData->slugKebab(),
            Variable::WORKSPACE_SLUG_SNAKE->value => $workspaceData->slugSnake(),
            ...$this->loadEnvReplacers($workspaceData),
        ];

        $replaced = preg_replace_callback(
            Variable::PLACEHOLDER,
            function (array $matches) use ($replacers, $workspaceData): string {
                $name = trim($matches[1]);

                if (array_key_exists($name, $replacers)) {
                    return (string) $replacers[$name];
                }

                if (Variable::isEnvName($name)) {
                    throw UnresolvedVariable::missingEnvironmentVariable($name, $workspaceData->path);
                }

                throw UnresolvedVariable::unknownVariable($matches[0]);
            },
            $content,
        );

        return $replaced ?? throw UnresolvedVariable::replacementFailed(preg_last_error_msg());
    }

    protected function loadEnvReplacers(WorkspaceData $workspaceData): array
    {
        $file = $workspaceData->path.DIRECTORY_SEPARATOR.'.env';

        if (! File::isFile($file)) {
            return [];
        }

        return collect(Dotenv::parse(File::get($file)))
            ->mapWithKeys(function ($value, $key) {
                return ['ENV_'.$key => $value];
            })
            ->all();
    }
}
