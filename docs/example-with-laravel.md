# Example with Laravel

## Overview

This example assumes a Laravel project running on Laravel Herd Pro, using its Redis service for cache, its MySQL service for the database, and its MinIO service for local S3-compatible storage.

## Workflows

The configuration below lets a developer spin up a new development environment for a Laravel project, refresh that environment on demand, and tear it down when the work is finished.

The three Workflows form a cycle through the Workspace statuses. `up` requires `suspended` and ends `ready`, `refresh` requires and ends `ready`, and `down` requires `ready` and ends `suspended`. A new Workspace starts `suspended`, so `up` is the only one of the three available until it has run.

### Workflow: `up`

This Workflow copies the `.env` file from the Project's primary directory, then updates `APP_URL`, `AWS_BUCKET`, `DB_DATABASE`, and `REDIS_PREFIX` in it. It creates an S3 bucket for the environment if none exists, and creates a MySQL schema for the environment if none exists. It then installs Composer dependencies, installs NPM dependencies, builds front end assets, and links and secures the Laravel Herd local site. Its final step runs the `refresh` Workflow as a nested step, which migrates and seeds the database.

The first two steps are guarded so they only run in a linked Workspace, leaving the primary Workspace's own `.env` file alone.

#### `.laborforest/workflows/up.yaml`
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
    run: 'composer install -v --ansi --no-interaction'
  - name: 'Install NPM dependencies'
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

### Workflow: `refresh`

This Workflow drops all tables and migrates the database from scratch, empties the S3 bucket if it exists, clears the default queue, wipes the logs, and runs the database seeder. It resets a Workspace's data without tearing the environment down, so it leaves the Workspace `ready`.

It is run on its own from the `Workflows` menu, and it is also run as the final step of `up`.

#### `.laborforest/workflows/refresh.yaml`
```yaml
resource_type: workflow
require_status: ready
ending_status: ready
sort_order: 1
steps:
  - name: 'Run fresh migrations'
    type: shell
    run: 'php artisan -vvv migrate:fresh'
  - name: 'Empty S3 bucket'
    type: shell
    if: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3api head-bucket --bucket {{ ENV_AWS_BUCKET }}'
    run: 'aws --endpoint={{ ENV_AWS_ENDPOINT }} s3 rm s3://{{ ENV_AWS_BUCKET }} --recursive --include="*"'
    env:
        AWS_ACCESS_KEY_ID: '{{ ENV_AWS_ACCESS_KEY_ID }}'
        AWS_SECRET_ACCESS_KEY: '{{ ENV_AWS_SECRET_ACCESS_KEY }}'
        AWS_DEFAULT_REGION: '{{ ENV_AWS_DEFAULT_REGION }}'
  - name: 'Clear the default queue'
    type: shell
    run: 'php artisan queue:clear'
  - name: 'Wipe logs'
    type: shell
    run: 'truncate -s 0 storage/logs/laravel.log'
  - name: 'Run database seeder'
    type: shell
    run: 'php artisan -vvv db:seed'
```

### Workflow: `down`

This Workflow empties the S3 bucket if it exists, deletes that bucket, drops the MySQL schema if it exists, and removes the Laravel Herd local site. It leaves the Workspace `suspended`, which is the status a Workspace must be in before it can be removed and the status `up` requires to run again.

Its `sort_order` of `100` keeps it at the bottom of the `Workflows` menu, away from the Workflows that are run routinely.

#### `.laborforest/workflows/down.yaml`
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
