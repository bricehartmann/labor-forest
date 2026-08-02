<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\ProjectData;
use App\Data\WorkflowData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Data\WorkspaceStatusData;
use App\Enums\Directory;
use App\Enums\Disk;
use App\Enums\File;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\ProjectDirectoryExists;
use App\Exceptions\ProjectDirectoryNotFound;
use App\Exceptions\ProjectDirectoryNotGitRepository;
use App\Exceptions\ProjectNotFound;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ProjectsService
{
    use ManagesFiles;

    public function removeProject(string $uuid): void
    {
        $this->ensureBaseDirectoryExists();
        $projects = $this->loadProjects()->reject(fn (ProjectData $projectData) => $projectData->uuid === $uuid);
        $this->putBaseFile(File::PROJECTS->value, Yaml::dump($projects->toArray(), inline: 10));
    }

    /**
     * @throws InvalidProjectsFile
     * @throws ProjectDirectoryNotFound
     */
    public function updateProject(ProjectData $projectData): void
    {
        $this->ensureBaseDirectoryExists();

        $existingProjects = $this->loadProjects();
        $newProjects = collect();

        foreach ($existingProjects as $existingProject) {
            if ($existingProject->uuid === $projectData->uuid) {
                $newProjects->push($projectData);
            } else {
                $newProjects->push($existingProject);
            }
        }

        $this->putBaseFile(File::PROJECTS->value, Yaml::dump($newProjects->toArray(), inline: 10));
        $this->initializeProjectBaseDirectory($projectData->path);
    }

    /**
     * @throws InvalidProjectsFile
     * @throws ProjectDirectoryExists
     * @throws ProjectDirectoryNotFound
     * @throws ProjectDirectoryNotGitRepository
     */
    public function addProject(string $path): ProjectData
    {
        if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
            throw new ProjectDirectoryNotFound($path);
        }

        $projects = $this->loadProjects();

        if ($projects->contains('path', $path)) {
            throw new ProjectDirectoryExists($path);
        }

        if (! \Illuminate\Support\Facades\File::isDirectory($path.DIRECTORY_SEPARATOR.'.git')) {
            throw new ProjectDirectoryNotGitRepository($path);
        }

        $newProject = new ProjectData(
            uuid: Str::uuid()->toString(),
            path: $path,
        );

        $projects->push($newProject);

        $this->ensureBaseDirectoryExists();
        $this->putBaseFile(File::PROJECTS->value, Yaml::dump($projects->toArray(), inline: 10));
        $this->initializeProjectBaseDirectory($path);

        return $newProject;
    }

    /**
     * @return Collection<int, WorkspaceData>
     */
    public function loadProjectWorkspaces(string $path): Collection
    {
        $worktrees = rescue(fn () => app(GitWorktreeService::class)->listWorktrees($path), []);

        $workspaces = collect();

        foreach ($worktrees as $worktree) {
            $status = $this->loadProjectWorkspaceStatus($worktree->path);

            $workspaceData = new WorkspaceData(
                is_primary: $worktree->is_primary,
                path: $worktree->path,
                branch: $worktree->branch,
                status: $status,
            );

            $workspaces->push($workspaceData);
        }

        return $workspaces;
    }

    public function updateProjectWorkspaceStatus(string $path, WorkspaceStatus $workspaceStatus): void
    {
        $statusPath = implode(DIRECTORY_SEPARATOR, [
            $path,
            Directory::BASE->value,
            Directory::IGNORED->value,
            File::STATUS->value,
        ]);

        $this->initializeProjectBaseDirectory($path);
        \Illuminate\Support\Facades\File::put($statusPath, Yaml::dump((new WorkspaceStatusData($workspaceStatus))->toArray(), 10));
    }

    protected function loadProjectWorkspaceStatus(string $path): WorkspaceStatus
    {
        $statusPath = implode(DIRECTORY_SEPARATOR, [
            $path,
            Directory::BASE->value,
            Directory::IGNORED->value,
            File::STATUS->value,
        ]);

        if (! \Illuminate\Support\Facades\File::isFile($statusPath)) {
            return WorkspaceStatus::UNKNOWN;
        }

        $yaml = Yaml::parseFile($statusPath);
        $workspaceStatusData = WorkspaceStatusData::from($yaml);

        return $workspaceStatusData->status;
    }

    /**
     * @throws InvalidProjectsFile
     * @throws ProjectNotFound
     * @throws ProjectDirectoryNotFound
     */
    public function loadProject(string $uuid): ProjectData
    {
        $project = $this->loadProjects()->firstWhere('uuid', $uuid);

        if (! $project) {
            throw new ProjectNotFound($uuid);
        }

        $this->initializeProjectBaseDirectory($project->path);

        return $project;
    }

    /**
     * @return Collection<int, ProjectData>
     *
     * @throws InvalidProjectsFile when the file is unparseable, malformed, or fails validation
     */
    public function loadProjects(): Collection
    {
        $this->ensureDirectoryExists($this->makeRelativeBasePath(), Disk::USER_HOME->value);
        $this->ensureBaseFileExists(File::PROJECTS->value);

        $path = $this->makeRelativeBasePath(File::PROJECTS->value);

        try {
            $yaml = Yaml::parse($this->getBaseFile(File::PROJECTS->value));
        } catch (ParseException $e) {
            throw InvalidProjectsFile::fromParseError($path, $e);
        }

        if ($yaml === null) {
            return new Collection;
        }

        if (! is_array($yaml)) {
            throw InvalidProjectsFile::notAList($path, get_debug_type($yaml));
        }

        if (! array_is_list($yaml)) {
            throw InvalidProjectsFile::notAList($path, 'a mapping');
        }

        return $this->makeProjects($path, $yaml);
    }

    protected function initializeProjectBaseDirectory(string $path): void
    {
        if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
            throw new ProjectDirectoryNotFound($path);
        }

        $pathBaseDir = $path.DIRECTORY_SEPARATOR.Directory::BASE->value;

        if (! \Illuminate\Support\Facades\File::isDirectory($pathBaseDir)) {
            \Illuminate\Support\Facades\File::makeDirectory($pathBaseDir);
        }

        $pathIgnoredDir = $pathBaseDir.DIRECTORY_SEPARATOR.Directory::IGNORED->value;

        if (! \Illuminate\Support\Facades\File::isDirectory($pathIgnoredDir)) {
            \Illuminate\Support\Facades\File::makeDirectory($pathIgnoredDir);
        }

        $pathGitIgnore = $pathIgnoredDir.DIRECTORY_SEPARATOR.File::GIT_IGNORE->value;

        if (! \Illuminate\Support\Facades\File::isFile($pathGitIgnore)) {
            \Illuminate\Support\Facades\File::put($pathGitIgnore, '*'.PHP_EOL.'!'.File::GIT_IGNORE->value.PHP_EOL);
        }

        $pathStatus = $pathIgnoredDir.DIRECTORY_SEPARATOR.File::STATUS->value;

        if (! \Illuminate\Support\Facades\File::isFile($pathStatus)) {
            $statusData = new WorkspaceStatusData(WorkspaceStatus::SUSPENDED);
            \Illuminate\Support\Facades\File::put($pathStatus, Yaml::dump($statusData->toArray()));
        }

        $pathWorkflowsDir = $pathBaseDir.DIRECTORY_SEPARATOR.Directory::WORKFLOWS->value;

        if (! \Illuminate\Support\Facades\File::isDirectory($pathWorkflowsDir)) {
            \Illuminate\Support\Facades\File::makeDirectory($pathWorkflowsDir);
        }

        $pathWorkflowUp = $pathWorkflowsDir.DIRECTORY_SEPARATOR.File::WORKFLOW_UP->value;

        if (! \Illuminate\Support\Facades\File::isFile($pathWorkflowUp)) {
            $stepCopyEnv = new WorkflowStepData(
                name: 'Copy .env file from primary project directory',
                type: WorkflowStepType::SHELL,
                run: 'cp "{{ PROJECT_PRIMARY_DIR }}/.env" .env',
            );
            $workflowUp = new WorkflowData(
                sort_order: 0,
                steps: collect([$stepCopyEnv]),
            );

            \Illuminate\Support\Facades\File::put($pathWorkflowUp, Yaml::dump([
                $workflowUp->toArray(),
            ], inline: 10));
        }

        $pathWorkflowDown = $pathWorkflowsDir.DIRECTORY_SEPARATOR.File::WORKFLOW_DOWN->value;

        if (! \Illuminate\Support\Facades\File::isFile($pathWorkflowDown)) {
            $workflowDown = new WorkflowData(
                sort_order: 100,
                steps: collect(),
            );

            \Illuminate\Support\Facades\File::put($pathWorkflowDown, Yaml::dump([
                $workflowDown->toArray(),
            ], inline: 10));
        }
    }

    /**
     * Validate every entry, reporting all bad ones together rather than only the first.
     *
     * @param  list<mixed>  $entries
     * @return Collection<int, ProjectData>
     *
     * @throws InvalidProjectsFile
     */
    protected function makeProjects(string $path, array $entries): Collection
    {
        $projects = new Collection;
        $problems = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                $problems[] = sprintf('Entry %d: expected a mapping, found %s.', $index + 1, get_debug_type($entry));

                continue;
            }

            try {
                $projects->push(ProjectData::validateAndCreate($entry));
            } catch (ValidationException $e) {
                foreach ($e->validator->errors()->all() as $message) {
                    $problems[] = sprintf('Entry %d: %s', $index + 1, $message);
                }
            }
        }

        if ($problems !== []) {
            throw InvalidProjectsFile::withProblems($path, $problems);
        }

        return $projects;
    }
}
