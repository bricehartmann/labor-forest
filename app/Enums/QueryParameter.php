<?php

namespace App\Enums;

/**
 * Query string parameters the CLI drain paths use to carry a notification into the window.
 */
enum QueryParameter: string
{
    case ERROR = 'error';
    case SUCCESS = 'success';
    case BODY = 'body';
}
