<?php

namespace App\Services;

use App\Enums\File as FileName;
use App\Enums\HostEnvKey;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

class ProcessEnvironmentService
{
    /**
     * Names this application leaks into every child process, cached for the life of the request.
     *
     * @var array<int, string>|null
     */
    protected ?array $leakedNames = null;

    /**
     * Build the environment for a spawned process, stripping this application's own variables.
     *
     * Symfony merges the given environment on top of the parent's rather than replacing it, and
     * the NativePHP runtime hands the queue worker an APP_ENV, storage path, and bootstrap cache
     * paths of its own. A target repository loads its .env with Dotenv::safeLoad(), which leaves
     * an already-set variable alone, so without this every workflow step would run against this
     * application's configuration instead of the workspace's. A false value tells Symfony to drop
     * the variable outright.
     *
     * Color hints are forced on top of the stripped names, because a spawned process is handed a pipe
     * rather than a terminal and would otherwise emit plain text in a packaged build. A caller's own
     * value still wins over both.
     *
     * @param  array<string, string>  $env
     * @return array<string, string|false>
     */
    public function sanitized(array $env = []): array
    {
        $stripped = [];

        foreach ($this->leakedNames() as $name) {
            $stripped[$name] = false;
        }

        return [...$stripped, ...$this->forced(), ...$env];
    }

    /**
     * Build the color hints a spawned process needs to produce ANSI output.
     *
     * @return array<string, string>
     */
    protected function forced(): array
    {
        $forced = HostEnvKey::FORCED;

        // a real terminal type must survive, so only a host without one gets the fallback
        if (! getenv('TERM')) {
            $forced['TERM'] = HostEnvKey::FALLBACK_TERM;
        }

        return $forced;
    }

    /**
     * Collect every variable name this application puts into the environment of its children.
     *
     * @return array<int, string>
     */
    protected function leakedNames(): array
    {
        if ($this->leakedNames !== null) {
            return $this->leakedNames;
        }

        $names = array_map(fn (HostEnvKey $key): string => $key->value, HostEnvKey::cases());

        foreach (array_keys(getenv()) as $name) {
            if (HostEnvKey::isInjectedName((string) $name)) {
                $names[] = (string) $name;
            }
        }

        // Laravel's Env repository writes every key of this application's .env through putenv()
        $file = base_path(FileName::ENV->value);

        if (File::isFile($file)) {
            $names = [...$names, ...array_keys(Dotenv::parse(File::get($file)))];
        }

        return $this->leakedNames = array_values(array_filter(
            array_unique($names),
            fn (string $name): bool => ! HostEnvKey::isPreservedName($name),
        ));
    }
}
