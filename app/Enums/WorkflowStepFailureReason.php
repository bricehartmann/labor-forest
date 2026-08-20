<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Why a step failed without its own command ever producing an exit code.
 *
 * A failed step carrying none of these failed the ordinary way: its command ran and exited
 * non-zero. These name the cases where nothing the workflow author wrote ever got to answer.
 */
enum WorkflowStepFailureReason: string implements HasLabel
{
    case IF_GATE_FAILED = 'if-gate-failed';
    case IF_GATE_TIMED_OUT = 'if-gate-timed-out';
    case UNLESS_GATE_FAILED = 'unless-gate-failed';
    case UNLESS_GATE_TIMED_OUT = 'unless-gate-timed-out';
    case TIMEOUT = 'timeout';

    public function getLabel(): string
    {
        return match ($this) {
            self::IF_GATE_FAILED => 'the if condition could not be run',
            self::IF_GATE_TIMED_OUT => 'the if condition ran out of time',
            self::UNLESS_GATE_FAILED => 'the unless condition could not be run',
            self::UNLESS_GATE_TIMED_OUT => 'the unless condition ran out of time',
            self::TIMEOUT => 'the step ran out of time',
        };
    }

    /**
     * The reason for a broken `if` gate, told apart by whether the gate ran out of time.
     */
    public static function forIfGate(bool $timedOut): self
    {
        return $timedOut ? self::IF_GATE_TIMED_OUT : self::IF_GATE_FAILED;
    }

    /**
     * The reason for a broken `unless` gate, told apart by whether the gate ran out of time.
     */
    public static function forUnlessGate(bool $timedOut): self
    {
        return $timedOut ? self::UNLESS_GATE_TIMED_OUT : self::UNLESS_GATE_FAILED;
    }
}
