<?php

declare(strict_types=1);

namespace Conduit\Drivers;

use Conduit\DTOs\CliRunResult;
use Conduit\Exceptions\CliCommandException;
use Conduit\Exceptions\CliNotInstalledException;
use Conduit\Exceptions\CliTimeoutException;

class ClaudeDriver extends AbstractCliDriver
{
    public function __construct(
        string $binary = 'claude',
        protected string $model = 'claude-sonnet-4-5',
        protected int $maxTurns = 10,
        int $timeout = 600,
        /** @var list<string> */
        protected array $allowedTools = [],
        ?string $workingDirectory = null,
    ) {
        parent::__construct($binary, $timeout, $workingDirectory);
    }

    public function name(): string
    {
        return 'claude';
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws CliCommandException
     * @throws CliTimeoutException
     * @throws CliNotInstalledException
     */
    public function execute(string $prompt, array $options = []): CliRunResult
    {
        $this->ensureInstalled();

        // Create the temp file here so buildCommand() stays side-effect-free.
        // The finally block guarantees cleanup even if the process throws.
        $tempFile = null;
        if (isset($options['system_prompt'])) {
            $tempFile = tempnam(sys_get_temp_dir(), 'conduit-prompt-');
            if ($tempFile === false) {
                throw new \RuntimeException('Failed to create temp file for system prompt');
            }
            $written = file_put_contents($tempFile, (string) $options['system_prompt']);
            if ($written === false) {
                throw new \RuntimeException('Failed to write system prompt to temp file');
            }
            // Pass the resolved path so buildCommand() can reference it without creating its own.
            $options['system_prompt_file'] = $tempFile;
        }

        $args = $this->buildCommand($prompt, $options);

        try {
            $processResult = $this->runProcess($args, $options);

            if (! $processResult->success) {
                throw CliCommandException::fromResult($processResult, $args);
            }

            $model = (string) ($options['model'] ?? $this->model);

            return CliRunResult::fromClaudeJson($processResult->output, ['model' => $model]);
        } finally {
            if ($tempFile !== null && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Build the command arguments for a Claude CLI invocation.
     *
     * This method is side-effect-free. If a system prompt is provided, pass
     * the resolved temp file path via `$options['system_prompt_file']` (as
     * execute() does) rather than `$options['system_prompt']`, so no file
     * is created here.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function buildCommand(string $prompt, array $options = []): array
    {
        /** @var list<string> $args */
        $args = [$this->binary, '-p', $prompt, '--output-format', 'json'];

        $model = (string) ($options['model'] ?? $this->model);
        $args[] = '--model';
        $args[] = $model;

        $maxTurns = (int) ($options['max_turns'] ?? $this->maxTurns);
        $args[] = '--max-turns';
        $args[] = (string) $maxTurns;

        // Accept a pre-resolved temp file path (set by execute()).
        if (isset($options['system_prompt_file'])) {
            $args[] = '--system-prompt-file';
            $args[] = (string) $options['system_prompt_file'];
        }

        if (isset($options['append_system_prompt'])) {
            $args[] = '--append-system-prompt';
            $args[] = (string) $options['append_system_prompt'];
        }

        /** @var list<string> $tools */
        $tools = $options['allowed_tools'] ?? $this->allowedTools;
        if ($tools !== []) {
            $args[] = '--allowedTools';
            $args[] = implode(',', $tools);
        }

        if (isset($options['mcp_config'])) {
            $args[] = '--mcp-config';
            $args[] = (string) $options['mcp_config'];
        }

        if (isset($options['session_id'])) {
            $args[] = '--resume';
            $args[] = (string) $options['session_id'];
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    protected function driverSpecificEnvKeys(): array
    {
        return ['ANTHROPIC_API_KEY'];
    }

    /**
     * @return array<string, string|false>
     */
    protected function driverSpecificEnvOverrides(): array
    {
        // Unset CLAUDECODE to avoid nested session detection
        return ['CLAUDECODE' => false];
    }
}
