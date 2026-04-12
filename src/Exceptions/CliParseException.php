<?php

declare(strict_types=1);

namespace Conduit\Exceptions;

class CliParseException extends ConduitException
{
    public static function invalidJson(string $output, ?string $jsonError = null): self
    {
        return new self(
            message: 'Failed to parse CLI output as JSON'.($jsonError ? ': '.$jsonError : ''),
            output: self::truncate($output),
        );
    }

    public static function unexpectedFormat(string $message, ?string $output = null): self
    {
        return new self(
            message: 'Unexpected CLI output format: '.$message,
            output: $output !== null ? self::truncate($output) : null,
        );
    }

    protected static function truncate(string $output): string
    {
        return mb_strlen($output) > 500
            ? mb_substr($output, 0, 500).'…[truncated]'
            : $output;
    }
}
