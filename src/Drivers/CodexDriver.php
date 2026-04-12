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

        $args = $this->buildCommand($prompt, $options);

        $processResult = $this->runProcess($args, $options);

        if (! $processResult->success) {
            throw CliCommandException::fromResult($processResult, $args);
        }

        $model = (string) ($options['model'] ?? $this->model);

        return CliRunResult::fromCodexJson($processResult->output, ['model' => $model]);
    }

    /**
     * Build the command arguments for a Codex CLI invocation.
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

        // Codex has no native system-prompt flag — prepend to the prompt text.
        $args[] = $this->prependSystemPrompt($prompt, $options);
        $args[] = '--json';
        $args[] = '--full-auto';

        $model = (string) ($options['model'] ?? $this->model);
        $args[] = '-m';
        $args[] = $model;

        if (isset($options['working_directory'])) {
            $args[] = '-C';
            $args[] = (string) $options['working_directory'];
        }

        return $args;
    }

    /**
     * Prepend system prompt and append prompt to the user prompt.
     * Codex has no native system-prompt flag, so we inline everything.
     *
     * @param  array<string, mixed>  $options
     */
    protected function prependSystemPrompt(string $prompt, array $options): string
    {
        $parts = [];

        if (isset($options['system_prompt'])) {
            $parts[] = (string) $options['system_prompt'];
        }

        if (isset($options['append_system_prompt'])) {
            $parts[] = (string) $options['append_system_prompt'];
        }

        if ($parts !== []) {
            return implode("\n\n", $parts)."\n\n".$prompt;
        }

        return $prompt;
    }

    /**
     * @return list<string>
     */
    protected function driverSpecificEnvKeys(): array
    {
        return ['OPENAI_API_KEY'];
    }
}
