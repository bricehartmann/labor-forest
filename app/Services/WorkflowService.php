<?php

namespace App\Services;

use App\Data\WorkflowData;
use App\Exceptions\InvalidWorkflowFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class WorkflowService
{
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
