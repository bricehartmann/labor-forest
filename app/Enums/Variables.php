<?php

namespace App\Enums;

enum Variables: string
{
    case PROJECT_PRIMARY_DIR = 'PROJECT_PRIMARY_DIR';
    case PROJECT_SLUG_KEBAB = 'PROJECT_SLUG_KEBAB';
    case PROJECT_SLUG_SNAKE = 'PROJECT_SLUG_SNAKE';
    case WORKSPACE_DIR = 'WORKSPACE_DIR';
    case WORKSPACE_SLUG_KEBAB = 'WORKSPACE_SLUG_KEBAB';
    case WORKSPACE_SLUG_SNAKE = 'WORKSPACE_SLUG_SNAKE';

    public function example(): string
    {
        return match ($this) {
            self::PROJECT_PRIMARY_DIR => '~/code/project-name',
            self::PROJECT_SLUG_KEBAB => 'project-name',
            self::PROJECT_SLUG_SNAKE => 'project_name',
            self::WORKSPACE_DIR => '~/code/project-name-branch-name',
            self::WORKSPACE_SLUG_KEBAB => 'project-name-branch-name',
            self::WORKSPACE_SLUG_SNAKE => 'project_name_branch_name',
        };
    }
}
