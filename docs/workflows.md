# Workflows

## Overview
- Workflows are sets of sequential steps defined in `.yaml` files within the `.laborforest/workflows` directory of your project
- Workflows run on your local machine within the context of a Workspace
- Workflows are very loosely inspired by GitHub Actions
- Example Workflows are included: `Bare`, `Laravel`, and `JavaScript`

## Implementing workflows
- Workflows are managed manually through your YAML editor of choice
- Workflow file names should be `kebab-case` and end in `.yaml`
- The structure of a workflow is defined below:

```yaml
resource_type: workflow
require_status: <ready|suspended>
ending_status: <ready|suspended>
sort_order: <int>
steps:
  - name: 'Step 1'
    type: shell
    run: 'echo "Step 1"'
  - name: 'Step 2'
    type: shell
    run: 'echo "Step 2"'
```

### Required and ending statuses
- To require a Workspace is within a specific status in order to be run, use `require_status`
- To update a Workflow status after a successful run, use `ending_status`

### Sort order
- The `sort_order` attribute controls the order the Workflow appears in the `Workflows` table row action button menu.

### Types of Steps
- There are three types of steps:
  - `shell` - runs a shell command
  - `update_env` - updates values in the Workspace's `.env` file
  - `workflow` - runs a nested Workflow

#### Step type: `shell`
The `shell` step type runs a shell command.
```yaml
name: <name>
type: shell
run: <command>
if: <condition>
unless: <condition>
env:
  KEY_ONE: 'value1'
  KEY_TWO: 'value2'
```
- Only the `name`, `type`, and `run` attributes are required
- `if` or `unless` can be used to conditionally run the step
- `env` can be used for environment variables available to the `run`, `if`, and `unless` commands

#### Step type: `update_env`
The `update_env` step type updates the `.env` file in the Workspace directory. Any keys unspecified are left untouched.
```yaml
name: <name>
type: update_env
if: <condition>
unless: <condition>
map:
  KEY_ONE: 'value1'
  KEY_TWO: 'value2'
```
- Only the `name`, `type`, and `map` attributes are required
- `if` or `unless` can be used to conditionally run the step
- `map` is a map of key/value pairs to update

#### Step type: `workflow`
The `workflow` step type runs a nested workflow.
```yaml
name: <name>
type: workflow
run: <workflow>
```

- Nested workflows do not evaluate the `require_status` attribute.
- If a nested Workflow and the parent Workflow differ in `ending_status`, the parent's `ending_status` wins.
