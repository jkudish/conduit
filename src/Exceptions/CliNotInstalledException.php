<?php

declare(strict_types=1);

namespace Conduit\Exceptions;

class CliNotInstalledException extends ConduitException
{
    public function __construct(string $binary)
    {
        parent::__construct(
            message: "The CLI binary ({$binary}) is not installed or not available in PATH.",
        );
    }
}
