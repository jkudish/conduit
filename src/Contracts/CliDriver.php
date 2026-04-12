<?php

declare(strict_types=1);

namespace Conduit\Contracts;

use Conduit\DTOs\CliProcessResult;
use Conduit\DTOs\CliRunResult;

interface CliDriver
{
    /**
     * Check if the CLI binary is installed and available.
     */
    public function isInstalled(): bool;

    /**
     * Execute the CLI with a prompt and options.
     *
     * @param  array<string, mixed>  $options
     */
    public function execute(string $prompt, array $options = []): CliRunResult;

    /**
     * Run a raw CLI command with args.
     *
     * @param  list<string>  $args
     */
    public function run(array $args): CliProcessResult;

    /**
     * Get the driver name identifier.
     */
    public function name(): string;
}
