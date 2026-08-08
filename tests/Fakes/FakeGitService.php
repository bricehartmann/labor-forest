<?php

namespace Tests\Fakes;

use App\Services\GitService;
use Mockery;
use Symfony\Component\Process\Process;

/**
 * A GitService whose process construction is replaced by a queue of canned results, so no git
 * binary is ever spawned and the exact command and working directory can be asserted.
 */
final class FakeGitService extends GitService
{
    /**
     * Every command handed to gitProcess(), as a [command, cwd] pair.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    public array $commands = [];

    /**
     * The results each successive gitProcess() call reports, consumed first in first out.
     *
     * @var array<int, array{ok: bool, out?: string, err?: string}>
     */
    public array $responses = [];

    protected function gitProcess(string $command, string $cwd): Process
    {
        $this->commands[] = [$command, $cwd];

        $response = array_shift($this->responses) ?? ['ok' => true];

        $process = Mockery::mock(Process::class);
        $process->allows('run')->andReturns(0);
        $process->allows('isSuccessful')->andReturns($response['ok']);
        $process->allows('getOutput')->andReturns($response['out'] ?? '');
        $process->allows('getErrorOutput')->andReturns($response['err'] ?? '');

        return $process;
    }
}
