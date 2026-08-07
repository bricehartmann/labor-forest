# 🌲 LaborForest
A desktop app for MacOS to manage git worktrees and local workflows.

## Starting from scratch
Clone the repo and run the command below:

```shell
composer run setup
```

## Building for development
Application hot reloads upon changes.
```shell
composer run native:dev
```

## Building for production
Output: `nativephp/electron/dist/LaborForest-1.0.0-arm64.dmg`
```shell
php artisan native:build mac arm64
```

## Important directories
- `~/.laborforest` - tracks projects and settings
- `<project>/.laborforest/ignored` - tracks workspace status, holds workflow run logs
- `<project>/.laborforest/workflows` - holds workflows for the project

## Using without committing `.laborforest` directory
If you don't want to commit the `.laborforest` directory in your repo:
1. Add `.laborforest` to `.git/info/exclude`
2. Be sure to click `Continue without committing` after initializing a new project
3. The workflows in the primary workspace will be automatically copied to new worktrees

## Sample workflows (Laravel)

### `up.yaml`
```yaml
resource_type: workflow
require_status: suspended
ending_status: ready
sort_order: 0
steps:
  - name: 'Copy .env file from primary project directory'
    type: shell
    if: 'test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"'
    run: 'cp "{{ PROJECT_PRIMARY_DIR }}/.env" .env'
  - name: 'Update .env file'
    type: update_env
    if: 'test "{{ WORKSPACE_DIR }}" != "{{ PROJECT_PRIMARY_DIR }}"'
    map:
      APP_URL: 'https://{{ WORKSPACE_SLUG_KEBAB }}.test'
      AWS_BUCKET: '{{ WORKSPACE_SLUG_KEBAB }}'
      DB_DATABASE: '{{ WORKSPACE_SLUG_SNAKE }}'
      REDIS_PREFIX: '{{ WORKSPACE_SLUG_KEBAB }}-database-'
  - name: 'Create S3 bucket'
    type: shell
    unless: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3api head-bucket --bucket {{ ENV_AWS_BUCKET }}'
    run: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3api create-bucket --bucket {{ ENV_AWS_BUCKET }}'
    env:
      AWS_ACCESS_KEY_ID: '{{ ENV_AWS_ACCESS_KEY_ID }}'
      AWS_SECRET_ACCESS_KEY: '{{ ENV_AWS_SECRET_ACCESS_KEY }}'
      AWS_DEFAULT_REGION: '{{ ENV_AWS_DEFAULT_REGION }}'
  - name: 'Create MySQL schema'
    type: shell
    run: 'mysql -h {{ ENV_DB_HOST }} -P {{ ENV_DB_PORT }} -u {{ ENV_DB_USERNAME }} $([[ -z "{{ ENV_DB_PASSWORD }}" ]] && echo "--skip-password" || echo "-p{{ ENV_DB_PASSWORD }}") -e "CREATE DATABASE IF NOT EXISTS {{ ENV_DB_DATABASE }} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"'
  - name: 'Install Composer dependencies'
    type: shell
    run: 'composer install --no-interaction'
  - name: Install NPM dependencies
    type: shell
    run: 'npm ci'
  - name: 'Build assets'
    type: shell
    run: 'npm run build'
  - name: 'Link Laravel Herd site'
    type: shell
    run: 'herd link --no-interaction --secure {{ WORKSPACE_SLUG_KEBAB }}'
  - name: 'Refresh application data'
    type: workflow
    run: refresh
```

### `refresh.yaml`
```yaml
resource_type: workflow
require_status: ready
ending_status: ready
sort_order: 2
steps:
    - name: 'Run fresh migrations'
      type: shell
      run: 'php artisan -vvv migrate:fresh'
    - name: 'Run database seeder'
      type: shell
      run: 'php artisan -vvv db:seed'
```

### `down.yaml`
```yaml
resource_type: workflow
require_status: ready
ending_status: suspended
sort_order: 100
steps:
  - name: 'Empty S3 bucket'
    type: shell
    if: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3api head-bucket --bucket {{ ENV_AWS_BUCKET }}'
    run: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3 rm s3://{{ ENV_AWS_BUCKET }} --recursive --include="*"'
    env:
      AWS_ACCESS_KEY_ID: '{{ ENV_AWS_ACCESS_KEY_ID }}'
      AWS_SECRET_ACCESS_KEY: '{{ ENV_AWS_SECRET_ACCESS_KEY }}'
      AWS_DEFAULT_REGION: '{{ ENV_AWS_DEFAULT_REGION }}'
  - name: 'Delete S3 bucket'
    type: shell
    if: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3api head-bucket --bucket {{ ENV_AWS_BUCKET }}'
    run: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3api delete-bucket --bucket {{ ENV_AWS_BUCKET }}'
    env:
      AWS_ACCESS_KEY_ID: '{{ ENV_AWS_ACCESS_KEY_ID }}'
      AWS_SECRET_ACCESS_KEY: '{{ ENV_AWS_SECRET_ACCESS_KEY }}'
      AWS_DEFAULT_REGION: '{{ ENV_AWS_DEFAULT_REGION }}'
  - name: 'Drop MySQL schema'
    type: shell
    run: 'mysql -h {{ ENV_DB_HOST }} -P {{ ENV_DB_PORT }} -u {{ ENV_DB_USERNAME }} $([[ -z "{{ ENV_DB_PASSWORD }}" ]] && echo "--skip-password" || echo "-p{{ ENV_DB_PASSWORD }}") -e "DROP DATABASE IF EXISTS {{ ENV_DB_DATABASE }}"'
  - name: 'Remove Laravel Herd site'
    type: shell
    run: 'herd unlink --no-interaction {{ WORKSPACE_SLUG_KEBAB }}'
```
