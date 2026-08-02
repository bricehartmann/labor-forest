<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\ProjectData;
use App\Enums\Disk;
use App\Enums\File;
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

    /**
     * @throws InvalidProjectsFile
     * @throws ProjectDirectoryExists
     * @throws ProjectDirectoryNotFound
     * @throws ProjectDirectoryNotGitRepository
     */
    public function addProject(string $path): ProjectData
    {
        if (! \Illuminate\Support\Facades\File::isDirectory($path)) {
            throw new ProjectDirectoryNotFound($$path);
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
        $this->putBaseFile(File::PROJECTS->value, Yaml::dump($projects->toArray()));

        return $newProject;
    }

    /**
     * @throws InvalidProjectsFile
     * @throws ProjectNotFound
     */
    public function loadProject(string $uuid): ProjectData
    {
        $project = $this->loadProjects()->firstWhere('uuid', $uuid);

        if (! $project) {
            throw new ProjectNotFound($uuid);
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
