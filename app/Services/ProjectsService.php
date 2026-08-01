<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;
use App\Data\ProjectData;
use App\Enums\Disk;
use App\Enums\File;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

class ProjectsService
{
    use ManagesFiles;

    public function loadProjects(): Collection
    {
        $this->ensureDirectoryExists($this->makeRelativeBasePath(), Disk::USER_HOME->value);
        $this->ensureBaseFileExists(File::PROJECTS->value);
        $yaml = Yaml::parse($this->getBaseFile(File::PROJECTS->value));

        return ProjectData::collect($yaml, Collection::class);
    }
}
