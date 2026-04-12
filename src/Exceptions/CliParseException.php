<?php

declare(strict_types=1);

namespace Conduit\Exceptions;

class CliParseException extends ConduitException
{
    public static function invalidJson(string $output, ?string $jsonError = null): self
    {
        return new self(
            message: 'Failed to parse CLI output as JSON'.($jsonError ? ': '.$jsonError : ''),
            output: $output,
        );
    }

    public static function unexpectedFormat(string $message, ?string $output = null): self
    {
        return new self(
            message: 'Unexpected CLI output format: '.$message,
            output: $output,
        );
    }
}
