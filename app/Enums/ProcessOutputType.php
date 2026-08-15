<?php

namespace App\Enums;

/**
 * Which stream a chunk of a spawned process's output arrived on, as reported to a Process callback.
 *
 * The Laravel documentation describes the callback's type argument as "stdout" or "stderr", but the
 * value handed over is Symfony's shorter form, both for a real run and for a faked one.
 */
enum ProcessOutputType: string
{
    case STDOUT = 'out';
    case STDERR = 'err';
}
