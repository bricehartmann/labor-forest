<?php

namespace App\Rules;

use App\Enums\Variable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class ValidVariables implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $unknownVariables = [];

        $remainder = preg_replace_callback(
            Variable::PLACEHOLDER,
            function (array $matches) use (&$unknownVariables): string {
                $name = trim($matches[1]);

                if (! $this->isRecognizedVariable($name)) {
                    $unknownVariables[] = $matches[0];
                }

                return '';
            },
            $value,
        );

        if ($unknownVariables !== []) {
            $fail(sprintf(
                'Unknown variables: %s.',
                implode(', ', array_unique($unknownVariables)),
            ));
        }

        if (str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
            $fail('Unterminated {{ }} placeholder.');
        }
    }

    /**
     * Determine whether the variable name is enumerated or a valid environment passthrough.
     */
    private function isRecognizedVariable(string $name): bool
    {
        return Variable::tryFrom($name) !== null
            || Variable::isEnvName($name);
    }
}
