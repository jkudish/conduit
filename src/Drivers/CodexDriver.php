<?php

declare(strict_types=1);

namespace Conduit\Drivers;

use Conduit\DTOs\CliRunResult;
use Conduit\Exceptions\CliCommandException;
use Conduit\Exceptions\CliNotInstalledException;
use Conduit\Exceptions\CliTimeoutException;

class CodexDriver extends AbstractCliDriver
{
    public function __construct(
        string $binary = 'codex',
        protected string $model = 'gpt-5.3-codex',
        int $timeout = 300,
        ?string $workingDirectory = null,
    ) {
        parent::__construct($binary, $timeout, $workingDirectory);
    }

    public function name(): string
    {
        return 'codex';
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
        // Merge append_system_prompt into the instructions content (Codex has no native append flag).
        $tempFile = null;
        if (isset($options['system_prompt'])) {
            $instructions = (string) $options['system_prompt'];
            if (isset($options['append_system_prompt'])) {
                $instructions .= "\n\n".(string) $options['append_system_prompt'];
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'conduit-codex-prompt-');
            if ($tempFile === false) {
                throw new \RuntimeException('Failed to create temp file for Codex system prompt');
            }
            file_put_contents($tempFile, $instructions);
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

            return CliRunResult::fromCodexJson($processResult->output, ['model' => $model]);
        } finally {
            if ($tempFile !== null && file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Build the command arguments for a Codex CLI invocation.
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
        $args = [$this->binary, 'exec'];

        // Session resume uses the `resume` subcommand
        if (isset($options['session_id'])) {
            $args[] = 'resume';
            $args[] = (string) $options['session_id'];
        }

        $args[] = $prompt;
        $args[] = '--json';
        $args[] = '--full-auto';

        $model = (string) ($options['model'] ?? $this->model);
        $args[] = '-m';
        $args[] = $model;

        // Only accept a pre-resolved temp file path (set by execute()).
        // execute() already merges append_system_prompt into the instructions before creating the file.
        if (isset($options['system_prompt_file'])) {
            $args[] = '--instructions-file';
            $args[] = (string) $options['system_prompt_file'];
        }

        if (isset($options['working_directory'])) {
            $args[] = '-C';
            $args[] = (string) $options['working_directory'];
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    protected function driverSpecificEnvKeys(): array
    {
        return ['OPENAI_API_KEY'];
    }
}
