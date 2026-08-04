<?php

namespace App\Services;

use App\Data\WorkflowData;
use App\Enums\Directory;
use App\Enums\FileExtension;
use App\Enums\YamlResourceType;
use App\Exceptions\InvalidWorkflowFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowService
{
    /**
     * @throws InvalidWorkflowFile
     */
    public function loadSteps(string $workspacePath, string $workflowName): Collection
    {
        $workflowPath = implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
            $workflowName.'.'.FileExtension::YAML->value,
        ]);

        $workflow = $this->loadWorkflow($workflowPath);

        return $workflow->steps;
    }

    /**
     * @return Collection<string, WorkflowData> keyed by the workflow file name without its extension
     */
    public function loadWorkflows(string $workspacePath): Collection
    {
        $workflowsPath = implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
        ]);

        if (! File::isDirectory($workflowsPath)) {
            return collect();
        }

        return collect(File::files($workflowsPath))
            ->reject(fn (SplFileInfo $file) => $file->getExtension() !== FileExtension::YAML->value)
            ->filter(function (SplFileInfo $file) {
                $yaml = rescue(fn () => Yaml::parseFile($file->getPathname()));

                return is_array($yaml) && ($yaml['resource_type'] ?? null) === YamlResourceType::WORKFLOW->value;
            })
            ->mapWithKeys(fn (SplFileInfo $file) => [
                $file->getFilenameWithoutExtension() => rescue(fn () => $this->loadWorkflow($file->getPathname())),
            ])
            ->filter()
            ->reject(fn (WorkflowData $data) => $data->steps->isEmpty())
            ->sortBy(fn (WorkflowData $data) => $data->sort_order);
    }

    /**
     * @throws InvalidWorkflowFile when the file is missing, unparseable, malformed, or fails validation
     */
    public function loadWorkflow(string $path): WorkflowData
    {
        try {
            $yaml = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw InvalidWorkflowFile::fromParseError($path, $e);
        }

        if ($yaml !== null && ! is_array($yaml)) {
            throw InvalidWorkflowFile::notAMapping($path, get_debug_type($yaml));
        }

        try {
            return WorkflowData::validateAndCreate($yaml ?? []);
        } catch (ValidationException $e) {
            throw InvalidWorkflowFile::fromValidation($path, $e);
        }
    }
}
