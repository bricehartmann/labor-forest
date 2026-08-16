<?php

namespace Tests\Fakes;

use App\Services\ProcessEnvironmentService;

/**
 * A ProcessEnvironmentService that answers with a sentinel instead of reading the host environment.
 *
 * The real one scans getenv() and parses this application's own .env on every call. That used to be
 * skipped in service tests because the process seams were overridden wholesale; now that processes
 * are faked below the service, it runs for real — once per spawned process — and its File::isFile()
 * call reaches whatever File facade mock the test installed. ProcessEnvironmentServiceTest covers
 * the real behaviour, so these tests only need to know that a sanitized environment was passed on.
 */
final class FakeProcessEnvironmentService extends ProcessEnvironmentService
{
    /**
     * The sentinel key every faked process is expected to be handed.
     */
    public const string SENTINEL = 'LF_SANITIZED';

    /**
     * @param  array<string, string>  $env
     * @return array<string, string|false>
     */
    public function sanitized(array $env = []): array
    {
        return [self::SENTINEL => '1', ...$env];
    }
}
