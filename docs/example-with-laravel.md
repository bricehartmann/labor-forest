# Example with Laravel

## Overview
The below example assumes the following local environment setup for a Laravel project:
- Laravel Herd Pro
  - Redis service for cache
  - MySQL service for database
  - MinIO service for local S3-compatible storage

## Workflows
The below configuration of Workflows allows a developer to:
- quickly spin up a new development environment for a Laravel project
- refresh the development environment on demand
- easily tear down the development environment when work has concluded

### Workflow: `up`
The goals for the Workflows are:
- Setup (`up` Workflow)
  - Copy the .env file from the Project's primary directory
  - Update the .env file for:
    - `APP_URL`
    - `AWS_BUCKET`
    - `DB_DATABASE`
    - `REDIS_PREFIX`
  - Create an S3 bucket for the environment, if none exists
  - Create a MySQL schema for the environment, if none exists
  - Install Composer dependencies
  - Install NPM dependencies
  - Build front end assets
  - Link and secure the Laravel Herd local site
  - Migrate and Seed the database (via the `refresh` Workflow)

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
    run: 'composer install --no-interaction'
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
The goals for the Workflows are:
- Setup (`up` Workflow)
    - Copy the .env file from the Project's primary directory
    - Update the .env file for:
        - `APP_URL`
        - `AWS_BUCKET`
        - `DB_DATABASE`
        - `REDIS_PREFIX`
    - Create an S3 bucket for the environment, if none exists
    - Create a MySQL schema for the environment, if none exists
    - Install Composer dependencies
    - Install NPM dependencies
    - Build front end assets
    - Link and secure the Laravel Herd local site
    - Migrate and Seed the database (via the `refresh` Workflow)

#### `.laborforest/workflows/refresh.yaml`
