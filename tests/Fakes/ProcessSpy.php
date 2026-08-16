<?php

namespace Tests\Fakes;

use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/**
 * Records every faked process in call order and answers each one from a queue.
 *
 * Process::fake() records processes but exposes no ordered view of them: Factory::$recorded is
 * protected, and an assertRan() closure sees one process at a time with no index, so it can answer
 * "did any process match" but never "in what order" or "and nothing else". It also matches purely
 * on the command line, so it cannot tell two runs of the same command in different directories
 * apart. A single catch-all handler closure solves both, because the handler is passed the
 * PendingProcess it is answering for.
 */
final class ProcessSpy
{
    /**
     * Every faked process, as a [command, cwd] pair, in call order.
     *
     * @var array<int, array{0: string, 1: string|null}>
     */
    public array $commands = [];

    /**
     * The result each successive process reports, consumed first in first out. An exhausted queue
     * reports success with no output, so a happy-path test can set no responses at all.
     *
     * @var array<int, array{ok?: bool, out?: string, err?: string}>
     */
    public array $responses = [];

    /**
     * The pending process behind each recorded command, for asserting environment, timeout or
     * options — none of which the replaced seams could reach.
     *
     * @var array<int, PendingProcess>
     */
    public array $pending = [];

    /**
     * Install the recorder as the handler for every faked process.
     *
     * A FakeProcessResult is returned because it is the one type both run() and start() accept.
     * Note that an 'out' of exactly '0' is answered as an empty string, because
     * FakeProcessResult::normalizeOutput() short-circuits on empty() and empty('0') is true.
     */
    public static function install(): self
    {
        $spy = new self;

        Process::fake(function (PendingProcess $process) use ($spy): FakeProcessResult {
            $spy->commands[] = [$process->command, $process->path];
            $spy->pending[] = $process;

            $response = array_shift($spy->responses) ?? [];

            return Process::result(
                output: $response['out'] ?? '',
                errorOutput: $response['err'] ?? '',
                exitCode: ($response['ok'] ?? true) ? 0 : 1,
            );
        });

        return $spy;
    }
}
