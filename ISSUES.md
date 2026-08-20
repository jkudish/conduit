# Plan-mode issues

Open these as GitHub issues **in order**. Each issue is one PR. Do not start N+1 until N is merged, except where an issue says it can overlap.

GitHub Actions is out of credits — **do not add a workflow**. Gate on `composer test`, `composer phpstan`, and `composer lint:check` locally. Ignore red CI on PRs.

Parser tests should use JSON/JSONL **fixtures on disk** (`tests/Fixtures/{driver}/…`) rather than giant inline strings. Capture the CLI version in the filename when you have a real sample; otherwise keep a documented synthetic fixture.

---

## 1. 2.0 foundation: laravel/ai 0.10, Laravel 13, Pest 5

**Depends on:** nothing  
**Labels:** enhancement

### Why

Conduit implements the `laravel/ai` 0.5 `Gateway` (`generateText` / `streamText`). Current SDK is **0.10.3**. Custom gateways now implement `StepTextGateway`:

```php
generateTextStep(...): StepResponse
generateStreamStep(...): Generator
```

`generateText()` / `streamText()` / `onToolInvocation()` live on `TextGenerationLoop`. Until this lands, Laravel 13 apps pulling current `laravel/ai` will not run Conduit.

Pest 5 is an explicit target. `pestphp/pest-plugin-laravel` **v5.0.1** requires `php: ^8.4` and `laravel/framework: ^13.23.0`. That drops PHP 8.3 and Laravel 12. (`laravel/ai` 0.10 itself still allows Laravel 12 / PHP 8.3; Pest 5 is what forces the floor up.)

### Scope

- `composer.json`:
  - `"php": "^8.4"`
  - `"illuminate/support": "^13.0"`, `"illuminate/contracts": "^13.0"`
  - `"laravel/ai": "^0.10.3"`
  - `"symfony/process": "^7.4|^8.0"`
  - Dev: `orchestra/testbench: ^11.0`, `pestphp/pest: ^5.0`, `pestphp/pest-plugin-laravel: ^5.0`, `larastan/larastan: ^3.10`, `phpstan/phpstan: ^2.2`
  - `allow-plugins` for `pestphp/pest-plugin`
- Rewrite `ConduitGateway` and `FakeConduitGateway` onto `StepTextGateway`. Return `StepResponse` (text, usage, `ConduitMeta`, `FinishReason`). For streaming, either throw a dedicated exception **or** wrap the one-shot result as a single-step stream so `TextGenerationLoop` is satisfied — prefer the wrapper if the SDK requires a generator that completes.
- Re-read `GeneratesText` / `HasTextGateway` / `StreamsText` on 0.10. `textGateway()` types against `StepTextGateway`. Keep `ConduitProvider` thin.
- `ConduitMeta` still extends `Meta`; 0.10 added `citations` — pass empty.
- Update gateway tests to call `generateTextStep` (or exercise the provider loop). Boot Testbench where facades (`Context`, `Log`) need an app.
- Extract existing parser JSON from `tests/Unit/CliRunResultTest.php` into `tests/Fixtures/{claude,codex,amp,pi}/`.
- README + CONTRIBUTING: PHP 8.4+, Laravel 13, `laravel/ai` 0.10.x. Keep PHPStan **level 8**.
- Do **not** change CLI flags, DriverOptions, Cursor/Grok, or PHPStan level.

### Acceptance

- `composer test`, `composer phpstan` (level 8), `composer lint:check` pass locally.
- A Laravel 13 app can `Ai::provider('claude-cli')->generateText(...)` against this package (manually or via Testbench).
- No GitHub Actions file added.

### Out of scope

DriverOptions, new flags, new drivers, PHPStan 9+, streaming JSONL, ACP.

---

## 2. Replace mixed driver options with a DriverOptions DTO

**Depends on:** #1  
**Labels:** enhancement

### Why

`CliDriver::execute(string $prompt, array $options = [])` and `ConduitContext::toDriverOptions()` are `array<string, mixed>`. Every new CLI flag grows an untyped key. PHPStan 9/10 is pointless until this seam is typed.

### Scope

```php
final readonly class DriverOptions
{
    /**
     * @param  list<string>  $allowedTools
     * @param  list<string>  $disallowedTools
     */
    public function __construct(
        public ?string $model = null,
        public ?string $sessionId = null,
        public array $allowedTools = [],
        public array $disallowedTools = [],
        public ?string $systemPrompt = null,
        public ?string $appendSystemPrompt = null,
        public ?int $maxTurns = null,
        public ?string $mcpConfig = null,
        public ?string $workingDirectory = null,
        public ?int $timeout = null,
        public ?string $permissionMode = null,
        public ?string $jsonSchema = null,
        public ?string $thinking = null,
        public ?string $provider = null,
        public ?string $mode = null,
        public bool $bare = false,
        public bool $autoApprove = false,
    ) {}
}
```

- `CliDriver::execute(string $prompt, DriverOptions $options): CliRunResult`
- `buildCommand(string $prompt, DriverOptions $options): array`
- `ConduitContext` builds `DriverOptions` (keep the static API, change the output).
- Optional `DriverCapability` set (`Resume`, `Mcp`, `AllowedTools`, `JsonSchema`, `StreamingJson`, …) so the gateway can warn instead of silently dropping SDK `$tools` / `$schema`.
- Update all driver tests to pass the DTO. Leave CLI flag *behavior* as it is today (parity is the next issue).

### Acceptance

- No `array<string, mixed> $options` on `CliDriver` / driver `execute` / `buildCommand`.
- Existing command-building tests still pass (same argv for the same inputs).
- PHPStan stays at **level 8**.

### Out of scope

New CLI flags, PHPStan 9, Cursor/Grok.

---

## 3. PHPStan: keep 8 through the rewrite, then 9, then 10

**Depends on:** #2  
**Labels:** enhancement

See [ROADMAP.md](ROADMAP.md#phpstan-8-vs-9-vs-10) for the analysis. Short version: **do not jump to 10**. Stay on 8 until DriverOptions exists; 9 is the next useful level; 10 is a parser/array-shape follow-up.

### Scope (two stacked commits or a tiny follow-up PR)

1. **Level 9** on `src/` only. No baseline. Fix remaining explicit `mixed` (mostly JSON offset access in `CliRunResult` — array shapes or `is_array` narrowing, not `@phpstan-ignore`).
2. **Level 10** after 9 is green. Target implicit mixed: `config()`, `Context::get()`, untyped foreach values in parsers. Still `src/` only unless tests are cheap.
3. Optional later: `phpstan-deprecation-rules`, then `phpstan-strict-rules`. Not required to close this issue.

### Acceptance

- `phpstan.neon.dist` at level 9 with zero errors, then level 10 with zero errors.
- No `phpstan-baseline.neon` for Conduit’s own code.

### Out of scope

`bleedingEdge.neon`, analysing all of `vendor`, GitHub Actions.

---

## 4. Existing CLI parity (Claude, Codex, Amp, Pi)

**Depends on:** #2 (typed flags). Can start flag research in parallel, not the PR.  
**Labels:** enhancement

### Claude (`claude`)

- `--permission-mode` (headless will hang on default prompts)
- `--json-schema`
- `--bare` for isolated/CI-style runs
- `--disallowedTools` / distinguish `--tools` vs `--allowedTools`
- `--effort`, `--add-dir`, `--max-budget-usd` as options if they map cleanly
- Forward `CLAUDE_CODE_OAUTH_TOKEN` in addition to `ANTHROPIC_API_KEY`
- Default model: accept aliases (`sonnet`, `opus`, …) via config, stop treating `claude-sonnet-4-5` as eternal

### Codex (`codex`)

- `--output-schema`
- Sandbox flags; **`--full-auto` becomes opt-in** (`autoApprove` / config)
- `codex exec resume --last` when no session id
- Parse `turn.failed` / `error` JSONL events

### Amp (`amp`)

- **Resume:** `amp threads continue <thread-id> -x … --stream-json` (today `session_id` is ignored)
- Separate `--model` from `--mode`
- **`allow_all_tools` default `false`**

### Pi (`pi`)

- Re-verify JSONL against current `docs/json.md` (`message_end` authoritative; keep `turn_end` fallback)
- `PI_SKIP_VERSION_CHECK=1` in subprocess env
- No new transport (`--mode rpc` is later)

### Acceptance

- Command-builder tests for every new flag.
- Amp resume argv test: `threads continue` + thread id.
- README security note: auto-approve is opt-in.
- Fixtures updated if parsers change.
- Local Pest + PHPStan + Pint.

### Out of scope

Cursor, Grok, streaming generator, ACP.

---

## 5. Cursor and Grok CLI drivers

**Depends on:** #2  
**Labels:** enhancement

### Cursor (`cursor-cli`)

```bash
agent -p "prompt" --output-format json --model <model> --workspace <dir> --trust
```

- Binary: `agent`, fallback `cursor-agent`
- Auth: `CURSOR_API_KEY` / `--api-key`
- Resume: `--resume=<chatId>` / `--continue`
- Modes: `--mode=plan|ask`
- Sandbox: `--sandbox enabled|disabled`
- `--force` / `--yolo` only when `autoApprove` is true
- Parser: terminal `{type:"result", subtype:"success", result, session_id, duration_ms}` (Claude-like). Fixture under `tests/Fixtures/cursor/`.

### Grok (`grok-cli`)

```bash
grok --no-auto-update -p "prompt" --output-format json --model <model> --cwd <dir>
```

- Binary: `grok` (Grok Build, not npm `grok-agent`)
- Auth: `XAI_API_KEY`
- Resume: `-s/--session-id`, `-r/--resume`, `-c/--continue`
- `--json-schema`, `--max-turns`
- Always pass `--no-auto-update` in the subprocess
- `--always-approve` only when `autoApprove` is true
- Stream format name later is **`streaming-json`**, not `stream-json` — document it; do not implement streaming here
- Parser + fixture under `tests/Fixtures/grok/`

Register both in `ConduitServiceProvider` via the same driver map as the existing four. Config keys, `AboutCommand`, README table, env allowlists.

### Acceptance

- `Ai::provider('cursor-cli')` and `Ai::provider('grok-cli')` resolve.
- `isInstalled()` / `CliNotInstalledException` tests.
- Command-builder tests matching the argv above.
- Local Pest + PHPStan + Pint.

### Out of scope

ACP (`agent acp`, `grok agent stdio`), cloud agents, streaming.

---

## 6. Streaming JSONL and schema forwarding (park ACP)

**Depends on:** #4 and #5  
**Labels:** enhancement

### Scope

- If SDK `$schema` / `DriverOptions.jsonSchema` is set, pass `--json-schema` (Claude, Grok) or `--output-schema` (Codex). Drivers without a native flag: explicit warning or exception — **no silent drop**.
- Symfony `Process` incremental stdout. Parse JSONL in `generateStreamStep()`.
  - Claude / Amp / Cursor: `stream-json`
  - Grok: **`streaming-json`**
  - Codex: `--json`
  - Pi: `--mode json` (already JSONL)
- Capability check so callers know which provider can stream.

### Park

- Agent Client Protocol: `agent acp`, `grok agent stdio`, Pi `--mode rpc`
- Cloud transports (`--cloud`, Cursor cloud, Grok dashboard)
- Implementing Laravel AI `$tools` inside the CLI subprocess

### Acceptance

- At least one driver (Claude is the reference) has a streaming test fed by a fixture JSONL file.
- Schema is forwarded where supported; others fail loudly.
- README documents ACP as out of scope.

---

## Suggested GitHub titles

Paste as-is when opening issues:

1. `2.0: laravel/ai 0.10, Laravel 13, Pest 5 (PHP 8.4)`
2. `Replace array driver options with a DriverOptions DTO`
3. `PHPStan: stay at 8, then 9, then 10 (no baseline)`
4. `CLI parity: Claude permission-mode/schema/bare, Codex sandbox, Amp threads continue`
5. `Add Cursor (agent) and Grok Build (grok) CLI drivers`
6. `Streaming JSONL + schema forwarding; park ACP`
