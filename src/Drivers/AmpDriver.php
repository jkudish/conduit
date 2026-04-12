<?php

declare(strict_types=1);

namespace Conduit\Drivers;

use Conduit\DTOs\CliRunResult;
use Conduit\Exceptions\CliCommandException;
use Conduit\Exceptions\CliNotInstalledException;
use Conduit\Exceptions\CliTimeoutException;

class AmpDriver extends AbstractCliDriver
{
    public function __construct(
        string $binary = 'amp',
        protected string $mode = 'smart',
        int $timeout = 300,
        protected bool $allowAllTools = true,
        ?string $workingDirectory = null,
    ) {
        parent::__construct($binary, $timeout, $workingDirectory);
    }

    public function name(): string
    {
        return 'amp';
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

        return CliRunResult::fromAmpJson($processResult->output, [
            'model' => (string) ($options['mode'] ?? $options['model'] ?? $this->mode),
        ]);
    }

    /**
     * Build the command arguments for an Amp CLI invocation.
     *
     * Amp is stateless per invocation — session_id is ignored.
     * System prompt is prepended to the prompt text (no dedicated flag).
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function buildCommand(string $prompt, array $options = []): array
    {
        // Prepend system prompt to the user prompt if provided.
        // Amp has no dedicated system-prompt flag, so we inline it.
        $fullPrompt = $prompt;
        if (isset($options['system_prompt'])) {
            $fullPrompt = (string) $options['system_prompt']."\n\n".$fullPrompt;
        }

        // Amp has no native append-system-prompt flag.
        // Prepend the append content so resume context and clip summaries reach the model.
        if (isset($options['append_system_prompt'])) {
            $fullPrompt = (string) $options['append_system_prompt']."\n\n".$fullPrompt;
        }

        /** @var list<string> $args */
        $args = [$this->binary, '-x', $fullPrompt, '--stream-json'];

        if ($this->allowAllTools) {
            $args[] = '--dangerously-allow-all';
        }

        $mode = (string) ($options['mode'] ?? $options['model'] ?? $this->mode);
        $args[] = '--mode';
        $args[] = $mode;

        if (isset($options['mcp_config'])) {
            $args[] = '--mcp-config';
            $args[] = (string) $options['mcp_config'];
        }

        // session_id is intentionally ignored — Amp exec mode is stateless.
        // allowed_tools is not forwarded — Amp uses --dangerously-allow-all for tool access.

        return $args;
    }

    /**
     * @return list<string>
     */
    protected function driverSpecificEnvKeys(): array
    {
        return ['AMP_API_KEY'];
    }
}
