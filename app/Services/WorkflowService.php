<?php

namespace App\Services;

use App\Data\WorkflowData;
use Symfony\Component\Yaml\Yaml;

class WorkflowService
{
    public function loadWorkflow(string $path): WorkflowData
    {
        $yaml = Yaml::parseFile($path);

        return WorkflowData::from($yaml);
    }
}
