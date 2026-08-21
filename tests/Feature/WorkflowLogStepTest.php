<?php

use App\Data\WorkflowRunLogStepData;
use App\Enums\WorkflowStepFailureReason;
use App\Enums\WorkflowStepSkipReason;
use App\Enums\WorkflowStepType;
use App\Filament\Pages\WorkflowLog;
use App\Livewire\WorkflowLogStep;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->uuid = '11111111-1111-1111-1111-111111111111';
    $this->slug = 'repo-feature';
});

describe('rendering a shell step', function () {
    it('renders the name, run, exit code and output of a step that succeeded', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData()),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('Install dependencies')
            ->assertSee('text-success-500')
            ->assertSee('RUN')
            ->assertSee('composer install')
            ->assertSee('EXIT CODE')
            ->assertSee('OUTPUT')
            ->assertSee('done')
            ->assertDontSee('SKIP REASON')
            ->assertDontSee('IF CONDITION')
            ->assertDontSee('UNLESS CONDITION')
            ->assertDontSee('CHILD RUN')
            ->assertDontSee('ENV')
            ->assertDontSee('MAP');
    });

    it('renders the failing exit code and the danger colour of a step that failed', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(
                exitCode: 1,
                output: 'Could not open input file: composer',
            )),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('text-danger-500')
            ->assertSee('EXIT CODE')
            ->assertSeeHtml('<code>1</code>')
            ->assertSee('Could not open input file: composer')
            ->assertDontSee('SKIP REASON');
    });

    it('renders the skip reason label and no exit code for a step that was skipped', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(
                exitCode: null,
                output: '',
                skipReason: WorkflowStepSkipReason::NOT_SELECTED,
            )),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('SKIP REASON')
            ->assertSee(WorkflowStepSkipReason::NOT_SELECTED->getLabel())
            ->assertSee('RUN')
            ->assertDontSee('EXIT CODE');
    });

    it('renders the failure reason of a step whose gate broke, alongside its exit code', function () {
        // a broken gate is a failure rather than a skip, so the step must read as failed and still
        // say which gate it was that never answered
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(
                exitCode: 1,
                output: 'the if condition could not be run: sh: not executable',
                failureReason: WorkflowStepFailureReason::IF_GATE_FAILED,
            )),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('FAILURE REASON')
            ->assertSee(WorkflowStepFailureReason::IF_GATE_FAILED->getLabel())
            ->assertSee('EXIT CODE')
            ->assertDontSee('SKIP REASON');
    });

    it('renders no exit code and an empty output cell for a step that has not run yet', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(exitCode: null, output: '')),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('Install dependencies')
            ->assertSee('text-gray-500')
            ->assertDontSee('EXIT CODE')
            ->assertDontSee('SKIP REASON')
            ->assertSee('OUTPUT')
            ->assertSeeHtml('bg-black"><code></code>');
    });
});

describe('rendering the step conditions and variables', function () {
    it('renders the if and unless conditions when the step declares them', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(), [
                'if' => 'test -f composer.json',
                'unless' => 'test -d vendor',
            ]),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('IF CONDITION')
            ->assertSee('test -f composer.json')
            ->assertSee('UNLESS CONDITION')
            ->assertSee('test -d vendor');
    });

    it('renders the env and map entries when the step declares them', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(), [
                'env' => ['APP_ENV' => 'testing'],
                'map' => ['APP_URL' => 'http://localhost:8080'],
            ]),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('ENV')
            ->assertSee('APP_ENV')
            ->assertSee('testing')
            ->assertSee('MAP')
            ->assertSee('APP_URL')
            ->assertSee('http://localhost:8080');
    });

    it('omits the condition and variable rows when the step declares none of them', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData()),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertDontSee('IF CONDITION')
            ->assertDontSee('UNLESS CONDITION')
            ->assertDontSee('ENV')
            ->assertDontSee('MAP');
    });
});

describe('rendering a workflow step', function () {
    it('links a workflow step that started a child run to that run log', function () {
        $logId = '20240101T000000Z_repo-feature_down';

        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(
                name: 'Run the down workflow',
                type: WorkflowStepType::WORKFLOW,
                run: 'down',
                logId: $logId,
            )),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('CHILD RUN')
            ->assertSee($logId)
            ->assertSeeHtml(WorkflowLog::getUrl([
                'uuid' => $this->uuid,
                'slug' => $this->slug,
                'id' => $logId,
            ]));
    });

    it('omits the child run row for a workflow step that never started a child run', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(
                name: 'Run the down workflow',
                type: WorkflowStepType::WORKFLOW,
                exitCode: 1,
                output: 'workflow not found',
                run: 'down',
            )),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('EXIT CODE')
            ->assertDontSee('CHILD RUN');
    });
});

describe('rendering ansi output', function () {
    it('converts ansi escape codes in the output to html', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(
                output: "\e[31mInstallation failed\e[0m",
            )),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSee('OUTPUT')
            ->assertSeeHtml('<span style="background-color: black; color: darkred">Installation failed</span>')
            ->assertDontSee("\e[31m");
    });

    it('wraps output that carries no ansi escape codes in the default colours', function () {
        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(output: 'Installation succeeded')),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])
            ->assertOk()
            ->assertSeeHtml('<span style="background-color: black; color: white">Installation succeeded</span>')
            ->assertDontSeeHtml('color: darkred');
    });
});

describe('the locked route context', function () {
    it('renders the child run link from the uuid and slug it was mounted with', function () {
        $logId = '20240101T000000Z_other-repo-main_up';

        Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData(
                type: WorkflowStepType::WORKFLOW,
                run: 'up',
                logId: $logId,
            )),
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'slug' => 'other-repo-main',
        ])
            ->assertOk()
            ->assertSeeHtml('/projects/22222222-2222-2222-2222-222222222222/workspaces/other-repo-main/logs/'.$logId);
    });

    it('refuses to let the uuid and slug be written from the browser', function () {
        $component = Livewire::test(WorkflowLogStep::class, [
            'step' => workflowLogStepArray(componentRunLogStepData()),
            'uuid' => $this->uuid,
            'slug' => $this->slug,
        ])->assertOk();

        expect(fn () => $component->set('uuid', 'tampered'))
            ->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $component->set('slug', 'tampered'))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

/**
 * The `step` array a WorkflowLogStep is mounted with: a run log step serialised the way the parent log
 * page passes it down, with any keys the shared builder does not cover merged on top.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function workflowLogStepArray(WorkflowRunLogStepData $step, array $extra = []): array
{
    return [...$step->toArray(), ...$extra];
}
