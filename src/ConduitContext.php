<?php

declare(strict_types=1);

namespace Conduit;

use Illuminate\Support\Facades\Context;

/**
 * Request-scoped context for passing CLI-specific options to the Conduit gateway.
 *
 * ProcessAgentMessage sets this before calling agent()->prompt(), and the
 * ConduitGateway reads it during generateTextStep(). Uses Laravel's Context
 * facade under the hood for proper request/job scoping.
 */
class ConduitContext
{
    private const KEY_SESSION_ID = 'conduit.session_id';

    private const KEY_CLI_TOOLS = 'conduit.cli_tools';

    private const KEY_SYSTEM_PROMPT = 'conduit.system_prompt';

    private const KEY_APPEND_SYSTEM_PROMPT = 'conduit.append_system_prompt';

    private const KEY_MAX_TURNS = 'conduit.max_turns';

    private const KEY_MCP_CONFIG = 'conduit.mcp_config';

    private const KEY_WORKING_DIRECTORY = 'conduit.working_directory';

    /**
     * Set CLI context for the current request/job scope.
     *
     * @param  list<string>  $cliTools
     */
    public static function set(
        ?string $sessionId = null,
        array $cliTools = [],
        ?string $systemPrompt = null,
        ?string $appendSystemPrompt = null,
        ?int $maxTurns = null,
        ?string $mcpConfig = null,
        ?string $workingDirectory = null,
    ): void {
        // Flush first to prevent Context::add() throwing on duplicate keys
        // (e.g. stale session retry calling set() twice in the same job)
        self::flush();

        Context::add(self::KEY_SESSION_ID, $sessionId);
        Context::add(self::KEY_CLI_TOOLS, $cliTools);
        Context::add(self::KEY_SYSTEM_PROMPT, $systemPrompt);
        Context::add(self::KEY_APPEND_SYSTEM_PROMPT, $appendSystemPrompt);
        Context::add(self::KEY_MAX_TURNS, $maxTurns);
        Context::add(self::KEY_MCP_CONFIG, $mcpConfig);
        Context::add(self::KEY_WORKING_DIRECTORY, $workingDirectory);
    }

    public static function sessionId(): ?string
    {
        /** @var string|null */
        return Context::get(self::KEY_SESSION_ID);
    }

    /**
     * @return list<string>
     */
    public static function cliTools(): array
    {
        /** @var list<string> */
        return Context::get(self::KEY_CLI_TOOLS) ?? [];
    }

    public static function systemPrompt(): ?string
    {
        /** @var string|null */
        return Context::get(self::KEY_SYSTEM_PROMPT);
    }

    public static function appendSystemPrompt(): ?string
    {
        /** @var string|null */
        return Context::get(self::KEY_APPEND_SYSTEM_PROMPT);
    }

    public static function maxTurns(): ?int
    {
        /** @var int|null */
        return Context::get(self::KEY_MAX_TURNS);
    }

    public static function mcpConfig(): ?string
    {
        /** @var string|null */
        return Context::get(self::KEY_MCP_CONFIG);
    }

    public static function workingDirectory(): ?string
    {
        /** @var string|null */
        return Context::get(self::KEY_WORKING_DIRECTORY);
    }

    /**
     * Clear all Conduit context values.
     */
    public static function flush(): void
    {
        Context::forget([
            self::KEY_SESSION_ID,
            self::KEY_CLI_TOOLS,
            self::KEY_SYSTEM_PROMPT,
            self::KEY_APPEND_SYSTEM_PROMPT,
            self::KEY_MAX_TURNS,
            self::KEY_MCP_CONFIG,
            self::KEY_WORKING_DIRECTORY,
        ]);
    }

    /**
     * Build driver options array from the current context.
     *
     * @return array<string, mixed>
     */
    public static function toDriverOptions(): array
    {
        return array_filter([
            'session_id' => self::sessionId(),
            'allowed_tools' => self::cliTools() !== [] ? self::cliTools() : null,
            'system_prompt' => self::systemPrompt() !== '' ? self::systemPrompt() : null,
            'append_system_prompt' => self::appendSystemPrompt() !== '' ? self::appendSystemPrompt() : null,
            'max_turns' => self::maxTurns(),
            'mcp_config' => self::mcpConfig() !== '' ? self::mcpConfig() : null,
            'working_directory' => self::workingDirectory() !== '' ? self::workingDirectory() : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
