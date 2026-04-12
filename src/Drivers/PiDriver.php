<?php

declare(strict_types=1);

namespace Conduit\Drivers;

use Conduit\DTOs\CliRunResult;
use Conduit\Exceptions\CliCommandException;
use Conduit\Exceptions\CliNotInstalledException;
use Conduit\Exceptions\CliTimeoutException;

class PiDriver extends AbstractCliDriver
{
    public function __construct(
        string $binary = 'pi',
        protected string $model = 'claude-sonnet-4-5',
        protected string $provider = 'anthropic',
        int $timeout = 600,
        /** @var list<string> */
        protected array $tools = [],
        ?string $workingDirectory = null,
    ) {
        parent::__construct($binary, $timeout, $workingDirectory);
    }

    public function name(): string
    {
        return 'pi';
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

        return CliRunResult::fromPiJson($processResult->output, [
            'model' => (string) ($options['model'] ?? $this->model),
        ]);
    }

    /**
     * Build the command arguments for a Pi CLI invocation.
     *
     * Pi supports `--system-prompt` as inline text (no file flag),
     * and `--tools` as a comma-separated list of built-in tools.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public function buildCommand(string $prompt, array $options = []): array
    {
        /** @var list<string> $args */
        $args = [$this->binary, '-p', $prompt, '--mode', 'json'];

        $model = (string) ($options['model'] ?? $this->model);
        $args[] = '--model';
        $args[] = $model;

        $provider = (string) ($options['provider'] ?? $this->provider);
        $args[] = '--provider';
        $args[] = $provider;

        // System prompt as inline text (Pi has no --system-prompt-file flag)
        // Merge append_system_prompt into the system prompt since Pi has no append flag.
        if (isset($options['system_prompt']) || isset($options['append_system_prompt'])) {
            $systemParts = [];
            if (isset($options['system_prompt'])) {
                $systemParts[] = (string) $options['system_prompt'];
            }
            if (isset($options['append_system_prompt'])) {
                $systemParts[] = (string) $options['append_system_prompt'];
            }
            $args[] = '--system-prompt';
            $args[] = implode("\n\n", $systemParts);
        }

        // Tools
        /** @var list<string> $tools */
        $tools = $options['allowed_tools'] ?? $this->tools;
        if ($tools !== []) {
            $args[] = '--tools';
            $args[] = implode(',', $tools);
        }

        // Thinking level
        if (isset($options['thinking'])) {
            $args[] = '--thinking';
            $args[] = (string) $options['thinking'];
        }

        // Session resume via --session flag
        if (isset($options['session_id'])) {
            $args[] = '--session';
            $args[] = (string) $options['session_id'];
        }

        // Ephemeral mode for pipeline runs (no session saving)
        if (! isset($options['session_id'])) {
            $args[] = '--no-session';
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    protected function driverSpecificEnvKeys(): array
    {
        return ['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY'];
    }
}
