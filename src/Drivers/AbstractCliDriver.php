<?php

declare(strict_types=1);

namespace Conduit\Drivers;

use Conduit\Contracts\CliDriver;
use Conduit\DTOs\CliProcessResult;
use Conduit\DTOs\CliRunResult;
use Conduit\Exceptions\CliCommandException;
use Conduit\Exceptions\CliNotInstalledException;
use Conduit\Exceptions\CliTimeoutException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

abstract class AbstractCliDriver implements CliDriver
{
    public function __construct(
        protected string $binary,
        protected int $timeout = 300,
        protected ?string $workingDirectory = null,
    ) {}

    public function isInstalled(): bool
    {
        if (str_contains($this->binary, DIRECTORY_SEPARATOR)) {
            return is_file($this->binary) && is_executable($this->binary);
        }

        return (new ExecutableFinder)->find($this->binary) !== null;
    }

    /**
     * @throws CliNotInstalledException
     */
    public function ensureInstalled(): void
    {
        if (! $this->isInstalled()) {
            throw new CliNotInstalledException($this->binary);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws CliCommandException
     * @throws CliTimeoutException
     * @throws CliNotInstalledException
     */
    abstract public function execute(string $prompt, array $options = []): CliRunResult;

    /**
     * Build the command arguments for a CLI invocation.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    abstract public function buildCommand(string $prompt, array $options = []): array;

    /**
     * @param  list<string>  $args
     *
     * @throws CliTimeoutException
     */
    public function run(array $args): CliProcessResult
    {
        return $this->runProcess($args);
    }

    /**
     * Build isolated environment variables for the subprocess.
     *
     * Only forwards essential env vars to prevent leaking secrets.
     * Override driverSpecificEnvKeys() to add driver-specific vars.
     *
     * @return array<string, string|false>
     */
    protected function buildEnv(): array
    {
        $allowedKeys = [
            'PATH', 'HOME', 'USER', 'SHELL', 'LANG', 'LC_ALL', 'TERM',
            'TMPDIR', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME',
            'NO_COLOR', 'FORCE_COLOR',
            ...$this->driverSpecificEnvKeys(),
        ];

        $env = [];
        foreach ($allowedKeys as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        foreach ($this->driverSpecificEnvOverrides() as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }

    /**
     * Additional env var keys this driver needs forwarded.
     *
     * @return list<string>
     */
    protected function driverSpecificEnvKeys(): array
    {
        return [];
    }

    /**
     * Env var overrides (e.g. explicitly unsetting vars).
     *
     * @return array<string, string|false>
     */
    protected function driverSpecificEnvOverrides(): array
    {
        return [];
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, mixed>  $options
     */
    protected function createProcess(array $args, array $options = []): Process
    {
        $workingDirectory = (string) ($options['working_directory'] ?? $this->workingDirectory ?? getcwd());
        $timeout = (int) ($options['timeout'] ?? $this->timeout);

        $process = new Process(
            $args,
            $workingDirectory !== '' ? $workingDirectory : null,
            $this->buildEnv(),
        );
        $process->setTimeout($timeout);

        return $process;
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, mixed>  $options
     *
     * @throws CliTimeoutException
     */
    protected function runProcess(array $args, array $options = []): CliProcessResult
    {
        $process = $this->createProcess($args, $options);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timeout = (int) ($options['timeout'] ?? $this->timeout);
            throw CliTimeoutException::exceeded($timeout, $this->binary);
        }

        return new CliProcessResult(
            success: $process->isSuccessful(),
            output: $process->getOutput(),
            error: $process->getErrorOutput(),
            exitCode: $process->getExitCode() ?? 1,
        );
    }
}
