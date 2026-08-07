<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\ProjectData;
use App\Data\WorkflowData;
use App\Data\WorkflowStepData;
use App\Data\WorkspaceData;
use App\Data\WorkspaceStatusData;
use App\Data\WorktreeData;
use App\Enums\Directory;
use App\Enums\Disk;
use App\Enums\File;
use App\Enums\FileExtension;
use App\Enums\GitStatus;
use App\Enums\WorkflowStepType;
use App\Enums\WorkspaceStatus;
use App\Enums\YamlResourceType;
use App\Exceptions\GitOperationFailed;
use App\Exceptions\GitStatusNotClean;
use App\Exceptions\InvalidProjectsFile;
use App\Exceptions\ProjectDirectoryExists;
use App\Exceptions\ProjectDirectoryNotFound;
use App\Exceptions\ProjectDirectoryNotGitRepository;
use App\Exceptions\ProjectNotFound;
use App\Exceptions\WorkspaceNotFound;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ProjectsService
{
    use ManagesFiles;

    /**
     * @return Collection<int, string>
     *
     * @throws GitOperationFailed
     */
    public function listProjectLocalBranches(string $path, bool $onlyBranchesWithoutExistingWorkspace): Collection
    {
        $localBranches = app(GitService::class)->listLocalBranches($path);

        if (! $onlyBranchesWithoutExistingWorkspace) {
            return $localBranches;
        }

        $workspaces = $this->loadProjectWorkspaces($path);

        return $localBranches
            ->reject(fn (string $branch) => $workspaces->contains('branch', $branch))
            ->values();
    }

    public function removeProject(string $uuid): void
    {
        $this->ensureBaseDirectoryExists();
        $projects = $this->loadProjects()->reject(fn (ProjectData $projectData) => $projectData->uuid === $uuid)->values();
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
        $this->initializeProjectWorkspaceBaseDirectory($projectData->path);
    }

    /**
     * @throws GitOperationFailed
     * @throws GitStatusNotClean
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

        if (! app(GitService::class)->isStatusClean($path)) {
            throw new GitStatusNotClean($path);
        }

        $newProject = new ProjectData(
            uuid: Str::uuid()->toString(),
            path: $path,
            last_opened: now()->timestamp,
        );

        $projects->push($newProject);

        $this->ensureBaseDirectoryExists();
        $this->putBaseFile(File::PROJECTS->value, Yaml::dump($projects->toArray(), inline: 10));
        $this->initializeProjectWorkspaceBaseDirectory($path);

        return $newProject;
    }

    public function addProjectWorkspace(
        ProjectData $projectData,
        string $branch,
        ?string $baseBranch,
    ): WorkspaceData {
        $parentDir = $projectData->parentDir();
        $projectDir = $projectData->dirName();
        $branchSlug = Str::slug(Str::replace('/', '-', $branch));
        $newWorkspacePath = $parentDir.DIRECTORY_SEPARATOR.$projectDir.'-'.$branchSlug;

        $worktreeData = app(GitService::class)->addWorktree(
            mainWorktreePath: $projectData->path,
            newWorktreePath: $newWorkspacePath,
            branch: $branch,
            baseBranch: $baseBranch,
        );

        $this->initializeProjectWorkspaceBaseDirectory($worktreeData->path);
        $this->syncWorkspaceWorkflowsFromPrimary($projectData->path, $worktreeData->path);

        return new WorkspaceData(
            is_primary: false,
            path: $worktreeData->path,
            branch: $worktreeData->branch,
            status: WorkspaceStatus::SUSPENDED,
            git_status: $this->loadProjectWorkspaceGitStatus($worktreeData->path),
        );
    }

    /**
     * @return Collection<int, WorkspaceData>
     */
    public function loadProjectWorkspaces(string $path): Collection
    {
        $worktrees = collect(rescue(fn () => app(GitService::class)->listWorktrees($path), []));

        $primaryPath = $worktrees->firstWhere('is_primary', true)?->path;

        if ($primaryPath !== null) {
            $worktrees->each(fn (WorktreeData $worktreeData) => $this->syncWorkspaceWorkflowsFromPrimary($primaryPath, $worktreeData->path));
        }

        return $worktrees->map(fn (WorktreeData $worktreeData) => $this->makeWorkspaceData($worktreeData));
    }

    /**
     * @throws WorkspaceNotFound
     */
    public function loadProjectWorkspace(string $workspacePath): WorkspaceData
    {
        $worktrees = rescue(fn () => app(GitService::class)->listWorktrees($workspacePath), collect());

        $worktreeData = $worktrees->firstWhere('path', $workspacePath);

        if (! $worktreeData) {
            throw new WorkspaceNotFound($workspacePath);
        }

        return $this->makeWorkspaceData($worktreeData);
    }

    protected function makeWorkspaceData(WorktreeData $worktreeData): WorkspaceData
    {
        return new WorkspaceData(
            is_primary: $worktreeData->is_primary,
            path: $worktreeData->path,
            branch: $worktreeData->branch,
            status: $this->loadProjectWorkspaceStatus($worktreeData->path),
            git_status: $this->loadProjectWorkspaceGitStatus($worktreeData->path),
        );
    }

    public function updateProjectWorkspaceStatus(string $path, WorkspaceStatus $workspaceStatus): void
    {
        $statusPath = implode(DIRECTORY_SEPARATOR, [
            $path,
            Directory::BASE->value,
            Directory::IGNORED->value,
            File::STATUS->value,
        ]);

        $this->initializeProjectWorkspaceBaseDirectory($path);
        \Illuminate\Support\Facades\File::put($statusPath, Yaml::dump((new WorkspaceStatusData($workspaceStatus))->toArray(), 10));
    }

    public function loadProjectWorkspaceStatus(string $path): WorkspaceStatus
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

    protected function loadProjectWorkspaceGitStatus(string $path): GitStatus
    {
        return rescue(
            fn () => app(GitService::class)->isStatusClean($path)
                ? GitStatus::CLEAN
                : GitStatus::DIRTY,
            GitStatus::UNKNOWN,
            report: false,
        );
    }

    /**
     * @throws InvalidProjectsFile
     * @throws ProjectNotFound
     * @throws ProjectDirectoryNotFound
     */
    public function loadProject(string $uuid, bool $touch = false): ProjectData
    {
        $project = $this->loadProjects()->firstWhere('uuid', $uuid);

        if (! $project) {
            throw new ProjectNotFound($uuid);
        }

        $this->initializeProjectWorkspaceBaseDirectory($project->path);

        if ($touch) {
            $project->last_opened = now()->timestamp;
            $this->updateProject($project);
        }

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

    protected function initializeProjectWorkspaceBaseDirectory(string $path): void
    {
        $pathBaseDir = $this->ensureProjectBaseDirectoryExists($path);

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
    }

    public function initializeWorkspaceStarterWorkflows(string $path): void
    {
        $pathBaseDir = $this->ensureProjectBaseDirectoryExists($path);

        $pathWorkflowsDir = $pathBaseDir.DIRECTORY_SEPARATOR.Directory::WORKFLOWS->value;

        if (! \Illuminate\Support\Facades\File::isDirectory($pathWorkflowsDir)) {
            \Illuminate\Support\Facades\File::makeDirectory($pathWorkflowsDir);
        }

        $pathWorkflowUp = $pathWorkflowsDir.DIRECTORY_SEPARATOR.File::WORKFLOW_UP->value;

        if (! \Illuminate\Support\Facades\File::isFile($pathWorkflowUp)) {
            $stepCopyEnv = new WorkflowStepData(
                name: 'Copy .env file',
                type: WorkflowStepType::SHELL,
                if: 'test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"',
                run: 'cp "{{ PROJECT_PRIMARY_DIR }}/.env" .env'
            );
            $workflowUp = new WorkflowData(
                require_status: WorkspaceStatus::SUSPENDED,
                ending_status: WorkspaceStatus::READY,
                sort_order: 0,
                steps: collect([$stepCopyEnv]),
            );

            \Illuminate\Support\Facades\File::put($pathWorkflowUp, Yaml::dump($workflowUp->toArray(), inline: 10));
        }

        $pathWorkflowDown = $pathWorkflowsDir.DIRECTORY_SEPARATOR.File::WORKFLOW_DOWN->value;

        if (! \Illuminate\Support\Facades\File::isFile($pathWorkflowDown)) {
            $workflowDown = new WorkflowData(
                require_status: WorkspaceStatus::READY,
                ending_status: WorkspaceStatus::SUSPENDED,
                sort_order: 100,
                steps: collect(),
            );

            \Illuminate\Support\Facades\File::put($pathWorkflowDown, Yaml::dump($workflowDown->toArray(), inline: 10));
        }
    }

    /**
     * Git worktrees only materialize tracked files, so a gitignored `.laborforest/workflows`
     * directory is absent from every new workspace. Seed it from the primary worktree.
     */
    protected function syncWorkspaceWorkflowsFromPrimary(string $primaryPath, string $workspacePath): void
    {
        if ($primaryPath === $workspacePath) {
            return;
        }

        if (! \Illuminate\Support\Facades\File::isDirectory($workspacePath)) {
            return;
        }

        $destination = implode(DIRECTORY_SEPARATOR, [
            $workspacePath,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
        ]);

        if (\Illuminate\Support\Facades\File::isDirectory($destination)) {
            return;
        }

        $source = implode(DIRECTORY_SEPARATOR, [
            $primaryPath,
            Directory::BASE->value,
            Directory::WORKFLOWS->value,
        ]);

        if (! \Illuminate\Support\Facades\File::isDirectory($source)) {
            return;
        }

        \Illuminate\Support\Facades\File::copyDirectory($source, $destination);
    }

    public function doesAnyProjectWorkspaceWorkflowExist(string $path): bool
    {
        $pathBaseDir = $path.DIRECTORY_SEPARATOR.Directory::BASE->value;
        $pathWorkflowsDir = $pathBaseDir.DIRECTORY_SEPARATOR.Directory::WORKFLOWS->value;

        if (! \Illuminate\Support\Facades\File::isDirectory($pathWorkflowsDir)) {
            return false;
        }

        return collect(\Illuminate\Support\Facades\File::files($pathWorkflowsDir))
            ->reject(fn (SplFileInfo $file) => $file->getExtension() !== FileExtension::YAML->value)
            ->map(fn (SplFileInfo $file) => rescue(fn () => Yaml::parseFile($file->getPathname())))
            ->filter()
            ->contains('resource_type', YamlResourceType::WORKFLOW->value);
    }

    protected function ensureProjectBaseDirectoryExists(string $path): string
    {
        if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
            throw new ProjectDirectoryNotFound($path);
        }

        $pathBaseDir = $path.DIRECTORY_SEPARATOR.Directory::BASE->value;

        if (! \Illuminate\Support\Facades\File::isDirectory($pathBaseDir)) {
            \Illuminate\Support\Facades\File::makeDirectory($pathBaseDir);
        }

        return $pathBaseDir;
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

        if ($problems) {
            throw InvalidProjectsFile::withProblems($path, $problems);
        }

        return $projects->sortByDesc('last_opened');
    }
}
