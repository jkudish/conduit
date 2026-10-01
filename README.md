# Conduit

Laravel AI SDK gateway for CLI coding agents.

<p align="center">
  <strong>Use Claude Code, Codex CLI, Amp, and Pi as first-class AI providers in Laravel.</strong>
</p>

![Packagist Version](https://img.shields.io/packagist/v/jkudish/conduit)
![Packagist Downloads](https://img.shields.io/packagist/dt/jkudish/conduit)
![PHP Version](https://img.shields.io/packagist/php-v/jkudish/conduit)
![License](https://img.shields.io/github/license/jkudish/conduit)
[![Sponsor](https://img.shields.io/badge/sponsor-♥-ea4aaa)](https://github.com/sponsors/jkudish)

Conduit wraps CLI coding agents behind the Laravel AI SDK's `Gateway` interface. Route text generation calls to Claude Code, OpenAI Codex CLI, Amp, or Pi — with session resume, tool allowlists, cost tracking, and structured error handling.

## Install

```bash
composer require jkudish/conduit
php artisan vendor:publish --tag=conduit-config
```

The CLI binaries (`claude`, `codex`, `amp`, `pi`) must be installed and available in your system `PATH`. Conduit auto-detects them.

## Quick Start

```php
use function Laravel\Ai\agent;

// Generate text using Claude Code
$response = agent()->prompt(
    'Refactor the UserController to use form requests',
    provider: 'claude-cli',
    model: 'claude-sonnet-4-5',
);

echo $response->text;

// Access CLI-specific metadata
$meta = $response->meta; // ConduitMeta
echo $meta->sessionId;    // resume later
echo $meta->costUsd;      // cost tracking
echo $meta->durationMs;   // execution time
```

## Supported Drivers

| Driver | Provider ID | Binary | Features |
|--------|------------|--------|----------|
| **Claude Code** | `claude-cli` | `claude` | Session resume, tool allowlists, MCP config, system prompt file, cost tracking |
| **Codex CLI** | `codex-cli` | `codex` | Session resume, full-auto mode |
| **Amp** | `amp-cli` | `amp` | Streaming JSON output, mode selection (smart/fast), MCP config |
| **Pi** | `pi-cli` | `pi` | Multi-provider (Anthropic/OpenAI/Gemini), ephemeral mode, thinking levels |

Each driver is registered as an AI SDK provider via `AiManager::extend()` and implements the `Gateway` interface.

> **Security note:** Codex CLI runs in `--full-auto` mode and Amp defaults to `--dangerously-allow-all`. These agents can read, write, and execute files on your system. Never route untrusted user input directly to these providers without sandboxing. Always set a `workingDirectory` to scope file access.

## Configuration

Published config at `config/conduit.php`:

```php
return [
    'drivers' => [
        'claude' => [
            'binary' => env('CONDUIT_CLAUDE_BINARY', 'claude'),
            'model' => env('CONDUIT_CLAUDE_MODEL', 'claude-sonnet-4-5'),
            'max_turns' => (int) env('CONDUIT_CLAUDE_MAX_TURNS', 10),
            'timeout' => (int) env('CONDUIT_CLAUDE_TIMEOUT', 600),
            'allowed_tools' => [],
        ],

        'codex' => [
            'binary' => env('CONDUIT_CODEX_BINARY', 'codex'),
            'model' => env('CONDUIT_CODEX_MODEL', 'gpt-5.3-codex'),
            'timeout' => (int) env('CONDUIT_CODEX_TIMEOUT', 300),
        ],

        'amp' => [
            'binary' => env('CONDUIT_AMP_BINARY', 'amp'),
            'mode' => env('CONDUIT_AMP_MODE', 'smart'),
            'timeout' => (int) env('CONDUIT_AMP_TIMEOUT', 300),
            'allow_all_tools' => (bool) env('CONDUIT_AMP_ALLOW_ALL_TOOLS', true),
        ],

        'pi' => [
            'binary' => env('CONDUIT_PI_BINARY', 'pi'),
            'model' => env('CONDUIT_PI_MODEL', 'claude-sonnet-4-5'),
            'provider' => env('CONDUIT_PI_PROVIDER', 'anthropic'),
            'timeout' => (int) env('CONDUIT_PI_TIMEOUT', 600),
            'tools' => [],
        ],
    ],
];
```

Also register the providers you use in your application's `config/ai.php`:

```php
'providers' => [
    'claude-cli' => ['driver' => 'claude-cli'],
    'codex-cli' => ['driver' => 'codex-cli'],
    'amp-cli' => ['driver' => 'amp-cli'],
    'pi-cli' => ['driver' => 'pi-cli'],
],
```

## Key Features

### Session Resume

Continue a previous CLI session by passing the session ID:

```php
use Conduit\ConduitContext;

ConduitContext::set(
    sessionId: $previousResponse->meta->sessionId,
);

$response = agent()->prompt('Now add tests for the changes you made', provider: 'claude-cli');
```

### Tool Allowlists

Restrict which tools the CLI agent can use:

```php
ConduitContext::set(
    cliTools: ['Bash', 'Read', 'Write', 'Edit'],
);

$response = agent()->prompt('Fix the failing test', provider: 'claude-cli');
```

### MCP Config

Pass MCP server configuration to the CLI:

```php
ConduitContext::set(
    mcpConfig: storage_path('app/mcp-config.json'),
);
```

### Context Builder

`ConduitContext` is a static, request-scoped context builder that uses Laravel's `Context` facade under the hood:

```php
ConduitContext::set(
    sessionId: 'abc-123',
    cliTools: ['Bash', 'Read'],
    systemPrompt: 'You are a code reviewer...',
    appendSystemPrompt: 'Focus on security issues.',
    maxTurns: 5,
    mcpConfig: '/path/to/mcp.json',
    workingDirectory: '/path/to/project',
);
```

### Cost & Usage Tracking

Every response includes structured metadata:

```php
$meta = $response->meta;

$meta->costUsd;           // 0.0042 (0.00 for Codex — see note below)
$meta->promptTokens;      // 15200
$meta->completionTokens;  // 3800
$meta->durationMs;        // 12400
$meta->numTurns;          // 3
$meta->sessionId;         // 'abc-123-def'
$meta->isError;           // false
```

SDK usage is available as `$response->usage->inputTokens` and `$response->usage->outputTokens`, serialized as `input_tokens` and `output_tokens`. Input totals include cache reads and writes. Conduit's metadata retains the `promptTokens` / `completionTokens` names and `prompt_tokens` / `completion_tokens` keys, but now reports the same inclusive totals. Cache and reasoning breakdowns are not exposed by Conduit; the SDK's optional breakdown fields remain `null`.

> **Cost tracking note:** Codex CLI does not report monetary cost in its output. `costUsd` returns `0.00` for Codex requests. Inclusive token totals alone are insufficient for exact cost calculations when cached or reasoning tokens have different rates; obtain the category breakdown from the CLI/provider if needed.

### Working Directory

Set the project directory the CLI operates on:

```php
ConduitContext::set(
    workingDirectory: base_path(),
);
```

### Isolated Environment

Conduit builds a clean environment for each subprocess — only forwarding essential env vars (`PATH`, `HOME`, etc.) and driver-specific keys (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`). No secrets leak into the subprocess.

## Test Fakes

`FakeConduitGateway` records all calls and provides semantic assertions:

```php
use Conduit\Gateway\ConduitGateway;
use Conduit\Testing\FakeConduitGateway;
use Conduit\Responses\ConduitMeta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;

it('calls the CLI gateway', function () {
    $fake = new FakeConduitGateway;
    $fake->queueText('Refactoring complete!');

    // ... pass $fake to your code ...

    $fake->assertCalled(1);
    $fake->assertModelUsed('claude-sonnet-4-5');
    $fake->assertInstructionsContain('Refactor');
});

it('queues multiple responses', function () {
    $fake = new FakeConduitGateway;
    $fake->queueText('Step 1 done');
    $fake->queueText('Step 2 done');

    // Each generateTextStep() call shifts the next response
});
```

**Assertions:** `assertCalled(?int $times)`, `assertNotCalled()`, `assertInstructionsContain(string)`, `assertModelUsed(string)`.

## Error Handling

Conduit throws structured exceptions that carry the full command context:

| Exception | When |
|-----------|------|
| `CliNotInstalledException` | Binary not found in `PATH` |
| `CliTimeoutException` | Process exceeded timeout |
| `CliCommandException` | Non-zero exit code — includes command, stdout, stderr |
| `CliParseException` | Unrecognized JSON output format |

All exceptions extend `ConduitException` which carries `$command`, `$output`, and `$errorOutput`.

```php
use Conduit\Exceptions\CliCommandException;

try {
    $response = agent()->prompt('...', provider: 'claude-cli');
} catch (CliCommandException $e) {
    Log::error('CLI failed', [
        'command' => $e->command,
        'exitCode' => $e->getCode(),
        'error' => $e->errorOutput,
    ]);
}
```

## Requirements

- PHP 8.3+
- Laravel 12.62+
- Laravel AI SDK (`laravel/ai`) v1.0.1+
- At least one supported CLI agent installed locally

### Upgrading From AI SDK 0.5

Follow every crossed section of the [Laravel AI upgrade guide](https://github.com/laravel/ai/blob/v1.0.1/UPGRADE.md) (0.8 → 0.9 → 0.10 → 0.11 → 1.0). Direct gateway callers must use `generateTextStep()` with a `StepContext`, or `$provider->textGenerationLoop()->generate()` for a complete response; tool invocation callbacks now belong to the loop. Streaming and SDK-managed tools / structured output remain unsupported by Conduit.

Conduit has no conversation tables or migrations. Applications that use SDK remembered conversations must apply the guide's participant, approval, and steps/status migrations before deploying AI 1.0, and resolve pending approvals first. Update consumers of SDK usage properties and stored usage keys; historical rows retain the old names.

Laravel MCP is not applicable to this upgrade: `laravel/mcp` is neither a direct nor transitive dependency of Conduit, and CLI MCP configuration does not use Laravel MCP. Conduit adds no Laravel MCP dependency constraint.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Run `composer test`, `composer phpstan`, and `composer lint` before submitting.

## Security

Email joey@jkudish.com to report vulnerabilities. See [SECURITY.md](SECURITY.md).

## Sponsoring

If you find Conduit useful, consider [becoming a sponsor](https://github.com/sponsors/jkudish).

## License

MIT. See [LICENSE](LICENSE).
