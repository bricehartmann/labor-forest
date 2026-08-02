<?php

namespace App\Services;

use App\Concerns\Services\ManagesFiles;

class WorkspaceService
{
    use ManagesFiles;

    public function loadWorkspaces(string $projectPath): void {}
}
