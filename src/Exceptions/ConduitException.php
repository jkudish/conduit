<?php

declare(strict_types=1);

namespace Conduit\Exceptions;

use RuntimeException;

class ConduitException extends RuntimeException
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        string $message,
        public readonly array $command = [],
        public readonly ?string $output = null,
        public readonly ?string $errorOutput = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
