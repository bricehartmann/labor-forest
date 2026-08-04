<?php

namespace App\Enums;

enum YamlResourceType: string
{
    case WORKFLOW = 'workflow';
    case WORKFLOW_RUN_LOG = 'run_log';
}
