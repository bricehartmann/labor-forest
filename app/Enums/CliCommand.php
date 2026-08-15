<?php

namespace App\Enums;

enum CliCommand: string
{
    case ADD_PROJECT = 'add-project';
    case RUN_WORKFLOW = 'run-workflow';
}
