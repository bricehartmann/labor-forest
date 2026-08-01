<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Throwable;

/**
 * Base for the "a YAML file on disk is unusable" family of exceptions.
 *
 * Callers catch these to report the problem to the user, so the messages in
 * $problems are written to be shown verbatim rather than logged.
 */
abstract class InvalidYamlFile extends Exception
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly string $path,
        public readonly array $problems,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('The %s file [%s] is invalid: %s', static::label(), $path, $this->messagesAsString()),
            previous: $previous,
        );
    }

    /**
     * How this kind of file is referred to in messages, i.e. "settings".
     */
    abstract protected static function label(): string;

    /**
     * The file parsed, but its contents failed validation.
     */
    public static function fromValidation(string $path, ValidationException $exception): static
    {
        return new static($path, $exception->validator->errors()->all(), $exception);
    }

    /**
     * The file is not parseable YAML, or does not exist.
     */
    public static function fromParseError(string $path, ParseException $exception): static
    {
        return new static($path, [$exception->getMessage()], $exception);
    }

    /**
     * The file parsed to something other than the expected structure.
     */
    public static function notAMapping(string $path, string $actualType): static
    {
        return new static($path, [
            sprintf('Expected a mapping, found %s.', $actualType),
        ]);
    }

    /**
     * The file failed for reasons the caller has already described.
     *
     * @param  list<string>  $problems
     */
    public static function withProblems(string $path, array $problems, ?Throwable $previous = null): static
    {
        return new static($path, $problems, $previous);
    }

    /**
     * The problems joined into a single human-readable string.
     */
    public function messagesAsString(): string
    {
        return implode(' ', $this->problems);
    }
}
