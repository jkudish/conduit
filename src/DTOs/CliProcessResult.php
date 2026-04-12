<?php

declare(strict_types=1);

namespace Conduit\DTOs;

final readonly class CliProcessResult
{
    public function __construct(
        public bool $success,
        public string $output,
        public string $error,
        public int $exitCode,
    ) {}
}
