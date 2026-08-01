<?php

namespace App\Rules;

use App\Enums\Variables;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class ValidVariables implements ValidationRule
{
    /**
     * Matches a well-formed placeholder and captures its inner text.
     */
    private const string PLACEHOLDER = '/\{\{(.*?)\}\}/s';

    /**
     * Matches a dynamic environment variable passthrough, i.e. ENV_APP_URL.
     */
    private const string ENV_VARIABLE = '/^ENV_[A-Z][A-Z0-9_]*$/';

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
            self::PLACEHOLDER,
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
                'The :attribute contains unknown variables: %s.',
                implode(', ', array_unique($unknownVariables)),
            ));
        }

        if (str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
            $fail('The :attribute contains an unterminated {{ }} placeholder.');
        }
    }

    /**
     * Determine whether the variable name is enumerated or a valid environment passthrough.
     */
    private function isRecognizedVariable(string $name): bool
    {
        return Variables::tryFrom($name) !== null
            || preg_match(self::ENV_VARIABLE, $name) === 1;
    }
}
