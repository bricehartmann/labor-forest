<?php

namespace App\Enums;

enum Regex: string
{
    case GIT_BRANCH_NAME = '#^(?!.*/\.)(?!\.)(?!.*\.\.)(?!/)(?!.*//)(?!.*\.lock$)(?!.*\.lock/)(?!.*@\{)[^\x00-\x20\x7f~^:?*\[\\\\]+(?<![/.])$#';
}
