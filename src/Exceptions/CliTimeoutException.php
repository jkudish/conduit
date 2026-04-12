<?php

declare(strict_types=1);

namespace Conduit\Exceptions;

class CliTimeoutException extends ConduitException
{
    public static function exceeded(int $timeout, string $binary): self
    {
        return new self(
            message: "CLI process ({$binary}) timed out after {$timeout} seconds.",
        );
    }
}
