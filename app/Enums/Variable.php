<?php

namespace App\Enums;

enum Variable: string
{
    /**
     * Matches a well-formed placeholder and captures its inner text.
     */
    public const string PLACEHOLDER = '/\{\{(.*?)\}\}/s';

    /**
     * Matches a dynamic environment variable passthrough, i.e. ENV_APP_URL.
     */
    public const string ENV_VARIABLE = '/^ENV_[A-Z][A-Z0-9_]*$/';

    case PROJECT_PRIMARY_DIR = 'PROJECT_PRIMARY_DIR';
    case PROJECT_SLUG_KEBAB = 'PROJECT_SLUG_KEBAB';
    case PROJECT_SLUG_SNAKE = 'PROJECT_SLUG_SNAKE';
    case WORKSPACE_DIR = 'WORKSPACE_DIR';
    case WORKSPACE_SLUG_KEBAB = 'WORKSPACE_SLUG_KEBAB';
    case WORKSPACE_SLUG_SNAKE = 'WORKSPACE_SLUG_SNAKE';

    /**
     * Determine whether the variable name is a dynamic environment variable passthrough.
     */
    public static function isEnvName(string $name): bool
    {
        return preg_match(self::ENV_VARIABLE, $name) === 1;
    }

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
