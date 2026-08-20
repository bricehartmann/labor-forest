<?php

namespace App\Concerns\Services;

use App\Enums\Directory;
use App\Enums\FileExtension;
use Illuminate\Support\Facades\File;

/**
 * Where a workspace keeps its workflow files, and which spelling of the extension holds each one.
 *
 * This is a filesystem convention rather than behaviour of any one service, so it lives here: both
 * WorkflowService and CliToolsService answer the same question, and neither should have to reach
 * through the other to ask it.
 */
trait ResolvesWorkflowFiles
{
    /**
     * The directory holding the workflow files of a workspace.
     */
    public function workflowsPath(string $workspacePath): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
        ]);
    }

    /**
     * The path of the file defining the named workflow, whether or not it exists.
     *
     * A name matching neither spelling resolves to the `.yaml` path, so a caller reporting a missing
     * workflow names the file the user is expected to write.
     */
    public function workflowPath(string $workspacePath, string $workflowName): string
    {
        return $this->findWorkflowPath($workspacePath, $workflowName)
            ?? $this->workflowPathWithExtension($workspacePath, $workflowName, FileExtension::YAML);
    }

    /**
     * The path of the file defining the named workflow, or null when there is no such file.
     *
     * Workflow files are authored by hand, so both spellings of the extension are read. A workspace
     * holding both keeps the `.yaml` one, because the two collide on a single workflow name.
     */
    public function findWorkflowPath(string $workspacePath, string $workflowName): ?string
    {
        foreach ([FileExtension::YAML, FileExtension::YML] as $extension) {
            $path = $this->workflowPathWithExtension($workspacePath, $workflowName, $extension);

            if (File::isFile($path)) {
                return $path;
            }
        }

        return null;
    }

    private function workflowPathWithExtension(string $workspacePath, string $workflowName, FileExtension $extension): string
    {
        return $this->workflowsPath($workspacePath).DIRECTORY_SEPARATOR.$workflowName.'.'.$extension->value;
    }
}
