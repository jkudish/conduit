# Conduit Modernization Roadmap

Research snapshot: **19 August 2026**. This is a sequenced plan, not a changelog. Dates below are calendar context for support windows, not effort estimates.

Conduit is a Laravel AI SDK gateway that shells out to coding-agent CLIs. The package is still on Laravel 12 + `laravel/ai` 0.5.1, talks to four CLIs (Claude Code, Codex, Amp, Pi), and already runs PHPStan 8 via Larastan. The modernization work is three stacked problems:

1. The Laravel AI SDK contract Conduit implements has been rewritten.
2. Static typing is present but still bag-of-mixeds at the driver boundary.
3. Two of the six requested agents have no driver, and the four that exist lag current CLI surfaces.

Treat this as a **2.0**. Dual-supporting Laravel 12 while rewriting the gateway is possible; keeping `laravel/ai` 0.5 compatibility after the gateway rewrite is not.

---

## Current vs target

| Layer | Today | Target |
| --- | --- | --- |
| PHP | `^8.3` | Keep `^8.3`; CI on 8.3 / 8.4 / 8.5 |
| Laravel | `illuminate/* ^12.0` | `^12.0 \|\| ^13.0` then drop 12 after a 2.x cycle |
| AI SDK | `laravel/ai ^0.5.1` | `^0.10.3` (current latest) |
| Testbench | `^10.0` (Laravel 12) | `^10 \|\| ^11` (Laravel 12 / 13) |
| Pest | `^4.0` | Stay on Pest 4 while PHP 8.3 is supported; Pest 5 wants PHP `^8.4` |
| PHPStan | `^2.0`, **level 8**, `src/` only | PHPStan `^2.2`, Larastan `^3.10`, **level 9 then 10**, include `tests/` |
| Drivers | Claude, Codex, Amp, Pi | + Cursor (`agent`), + Grok (`grok`) |
| Streaming | Throws; README says “v2” | `stream-json` / `streaming-json` where the CLI can emit it |
| Structured output | SDK `$schema` ignored | Forward `--json-schema` / `--output-schema` where the CLI supports it |
| CI | None | GitHub Actions: Pest + Pint + PHPStan on a PHP × Laravel matrix |

Laravel 13 shipped 17 March 2026 (PHP 8.3–8.5). Laravel 12 bugfix ended 13 August 2026; security support runs through 24 February 2027. Orchestra Testbench 11 tracks Laravel 13. Larastan 3.10 already allows Laravel 13.

---

## Why this is a breaking 2.0

`laravel/ai` 0.10 removed `TextGateway`. Provider gateways now implement `StepTextGateway`:

```php
generateTextStep(...): StepResponse
generateStreamStep(...): Generator
```

Multi-step `generateText()` / `streamText()` / `onToolInvocation()` moved onto `TextGenerationLoop`. Conduit’s `ConduitGateway` and `FakeConduitGateway` implement the old `Gateway` surface (`generateText`, `streamText`, audio/image/embeddings stubs). That rewrite is the critical path. Until it lands, “latest Laravel” is cosmetic: Laravel 13 apps will pull a current AI SDK and Conduit will not type-check or run against it.

Secondary breaking changes worth bundling into the same major:

- Amp `allow_all_tools` defaults to `true` (`--dangerously-allow-all`). Codex always passes `--full-auto`. Flip those to opt-in.
- Default model IDs (`claude-sonnet-4-5`, `gpt-5.3-codex`) should become aliases or documented current IDs, not frozen strings in constructors.
- `array<string, mixed> $options` should die in the public driver contract.

Keep Laravel 12 in `composer.json` for 2.0 if the AI SDK constraint is the only real 13-only pressure. Drop Laravel 12 in 2.1 or 3.0 once security support is close.

---

## Principles

1. **CLIs are the product; Laravel is the adapter.** Drivers should track documented headless flags, not try to be a generic LLM client.
2. **One typed options object, six command builders.** Stop growing `ConduitContext` + untyped `$options` in parallel.
3. **Capabilities, not identical flags.** Resume, MCP, tools, schema, and streaming are per-driver. The gateway maps SDK intent onto what the binary actually has.
4. **Safe defaults for subprocesses.** Auto-approve / skip-sandbox is explicit config, never the published default.
5. **Fixture-first parsers.** Each JSON/JSONL dialect lives in golden files captured from a real CLI version. Drivers pin a minimum CLI version in config and README.
6. **Do not invent ACP yet.** Cursor and Grok expose Agent Client Protocol. That is a third integration style (JSON-RPC stdio), not a CLI driver. Park it until the six print-mode drivers are current.

---

## Phase 0 — Make the repo shippable

Do this before any Laravel or driver work. It is the only phase that does not change public API.

- Add GitHub Actions: PHP 8.3/8.4/8.5 × Laravel 12 (Testbench 10), then expand to Laravel 13 (Testbench 11) in Phase 1.
- Run `composer test`, `composer phpstan`, `composer lint:check` on every PR.
- Add `pest.php` / TestCase if Testbench bootstrapping is needed once the gateway rewrite hits the container.
- Record CLI JSON fixtures under `tests/Fixtures/{claude,codex,amp,pi,cursor,grok}/` with the CLI version in the filename.
- Document minimum CLI versions in README (detect via `--version` later; do not block Phase 0 on detection).

---

## Phase 1 — Laravel 13 + `laravel/ai` 0.10

This is the load-bearing phase. Everything else rebases onto it.

### Composer

```json
"php": "^8.3",
"illuminate/support": "^12.0|^13.0",
"illuminate/contracts": "^12.0|^13.0",
"laravel/ai": "^0.10.3",
"symfony/process": "^7.0|^8.0"
```

Dev:

```json
"larastan/larastan": "^3.10",
"orchestra/testbench": "^10.0|^11.0",
"pestphp/pest": "^4.0",
"pestphp/pest-plugin-laravel": "^4.1",
"phpstan/phpstan": "^2.2"
```

Stay on Pest 4 while PHP 8.3 remains supported. `pest-plugin-laravel` 4.1 already understands Laravel 13. Pest 5 (`^5.0.1`) requires PHP `^8.4` and Laravel `^13.23` — that is a later, optional bump.

### Gateway rewrite

Replace `ConduitGateway implements Gateway` with a `StepTextGateway` implementation:

- `generateTextStep()` runs the CLI once and returns a `StepResponse` (text + usage + `ConduitMeta`).
- `generateStreamStep()` is the hook for Phase 4 streaming; for 2.0 it can still throw a dedicated `StreamingNotSupportedException` **or** emit a single-step stream that wraps `generateTextStep()`. Prefer the single-step wrapper so `TextGenerationLoop` stays happy.
- Drop the unused audio / image / embeddings / transcription methods unless the fat `Gateway` interface still requires them. After 0.10, `Gateway` extends `StepTextGateway` plus the other capability gateways — implement the extras as explicit “not supported” types if the SDK still type-hints the union, otherwise split to `StepTextGateway` only.

`ConduitProvider` already extends `Provider` and uses `GeneratesText` / `HasTextGateway` / `StreamsText`. Re-read those traits on 0.10 — `textGateway()` now types against `StepTextGateway`, and `textGenerationLoop()` is provided by `Provider`. The provider class itself should stay thin.

`FakeConduitGateway` must implement `StepTextGateway` and return canned `StepResponse` values so `Agent::fake()` / loop-driven tests work. The current `queueText()` helper stays; assert against recorded step calls, not `generateText()`.

`ConduitMeta` extends `Laravel\Ai\Responses\Data\Meta`. On 0.10, `Meta` gained a `citations` Collection. Keep the extra Conduit fields; pass citations through as empty unless a CLI starts reporting them.

### Service provider

`AiManager::extend()` is still the registration point. Confirm the 0.10 `create{Name}Driver` / config `driver` key still matches. Register six providers (`claude-cli`, `codex-cli`, `amp-cli`, `pi-cli`, `cursor-cli`, `grok-cli`) from a driver map instead of four copy-pasted closures.

### Config

Publish a `minimum_version` per driver. Add `permission_mode` / `sandbox` / `always_approve` keys with conservative defaults. Keep env-var names stable where they already exist.

---

## Phase 2 — Static types (Larastan + PHPStan)

The package already has the right tools at the wrong altitude. Level 8 plus `array<string, mixed>` is how `CliDriver::execute()` and `ConduitContext::toDriverOptions()` leak.

### Tooling

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon

parameters:
    paths:
        - src
        - tests
    level: 9          # then 10
    phpVersion: 80300 # bump the analysed version when CI adds 8.4 as minimum
    treatPhpDocTypesAsCertain: true
```

Sequence:

1. Lock PHPStan `^2.2` and Larastan `^3.10` (already Laravel 13-capable).
2. Add `phpstan/phpstan-deprecation-rules` so the AI SDK upgrade cannot rot.
3. Raise to **level 9** (explicit `mixed`). Baseline only if a vendor stub is the blocker; do not baseline Conduit’s own option bags.
4. Raise to **level 10** (implicit `mixed`). This is the actual “static types” milestone.
5. Turn on `phpstan-strict-rules` after 10 is clean.
6. Optional: `bleedingEdge.neon` once 10 is boring.

### Kill the mixed option bag

Replace `array<string, mixed> $options` with a readonly DTO (name bikeshed: `DriverOptions`).

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
        public ?string $provider = null,   // Pi
        public ?string $mode = null,       // Amp / Cursor
        public bool $bare = false,
    ) {}
}
```

`ConduitContext` becomes a builder for `DriverOptions`, not a parallel key-value store. `CliDriver::execute(string $prompt, DriverOptions $options): CliRunResult`. `buildCommand()` takes the same DTO.

Add a `DriverCapability` enum/set (`Resume`, `Mcp`, `AllowedTools`, `DisallowedTools`, `SystemPromptFlag`, `JsonSchema`, `StreamingJson`, `Sandbox`) so the gateway can log or throw when the SDK asks for something the binary cannot do — instead of silently ignoring `$tools` and `$schema` as it does today.

PHP 8.4 property hooks are available on CI, but do not require 8.4 in `composer.json` until Pest 5 / a later major. Readonly DTOs + enums on 8.3 are enough.

### Types that are already good

Keep `declare(strict_types=1)`, constructor property promotion, `list<string>` PHPDoc, and the readonly `CliRunResult` / `CliProcessResult`. The debt is at the seams: options, JSON parsers (`array<string, mixed>` events), and `ConduitGateway::extractPromptFromMessages(array $messages)`.

Tighten parsers with PHPStan array shapes or small event DTOs per CLI (`ClaudeResultEvent`, `CodexTurnCompleted`, …). Do not one-type every JSONL event on day one; shape the fields you persist onto `CliRunResult`.

---

## Phase 3 — Bring existing drivers current

Do this after the DTO exists so new flags do not grow more mixed keys.

### Claude Code (`claude`)

Current command: `claude -p <prompt> --output-format json --model … --max-turns … [--system-prompt-file] [--append-system-prompt] [--allowedTools] [--mcp-config] [--resume]`.

Gaps against the August 2026 CLI reference:

| Flag / behavior | Why it matters |
| --- | --- |
| `--permission-mode` (`default`, `acceptEdits`, `plan`, `auto`, `dontAsk`, `bypassPermissions`) | Headless runs currently inherit “default”, which can hang on permission prompts |
| `--json-schema` | Maps to Laravel AI structured output |
| `--output-format stream-json` + `--verbose` + `--include-partial-messages` | Streaming |
| `--bare` | Faster, isolated CI invocations |
| `--disallowedTools` / `--tools` | Allow-list vs availability vs deny — `--allowedTools` only pre-approves |
| `--system-prompt` (inline) | Avoids the temp-file dance when the prompt is small |
| `--effort` | Model effort (`low`…`ultracode`) |
| `--add-dir` | Extra readable/writable roots |
| `--max-budget-usd` | Hard spend cap |
| `--fork-session` | Resume without mutating the original transcript |
| `--plugin-dir` | Session-scoped plugins |
| Auth env | Forward `CLAUDE_CODE_OAUTH_TOKEN` / `ANTHROPIC_API_KEY` (already forwards the latter) |

Default `--model` should accept aliases (`sonnet`, `opus`, `haiku`, `fable`) and a documented full ID. Do not hardcode `claude-sonnet-4-5` as if it were stable.

### Codex CLI (`codex`)

Current command: `codex exec [resume <id>] <prompt> --json --full-auto -m <model> [-C <dir>]`.

Gaps:

- `--output-schema` (and on `exec resume` since ~May 2026) for structured output.
- Sandbox flags (`--sandbox workspace-write`, `--add-dir`) instead of always `--full-auto`. Make full-auto / `dangerously-bypass-approvals-and-sandbox` opt-in.
- `codex exec resume --last` when no session id is stored.
- Parse `turn.failed` / `error` events instead of only `agent_message`.
- Cost: still often absent; keep `costUsd = 0` but surface cached/reasoning tokens if the usage object includes them.
- `--output-last-message` as a fallback when JSONL is incomplete.

### Amp (`amp`)

Current command: `amp -x <prompt> --stream-json [--dangerously-allow-all] --mode <mode> [--mcp-config]`. **`session_id` is ignored.**

Gaps:

- Resume: `amp threads continue <thread-id> -x … --stream-json`. Thread IDs look like `T-<uuid>`. This is the highest-value Amp bug relative to the README feature table.
- Separate `--model` from `--mode` (`smart` / `fast` / `deep` / `rush`).
- Default `allow_all_tools` to `false`.
- `--stream-json-input` for later streaming / multi-turn on stdin.
- `--stream-json-thinking` is not Claude-compatible; keep it off unless a debug option asks for it.

### Pi (`pi`)

Current command is in good shape: `pi -p <prompt> --mode json --model --provider [--system-prompt] [--tools] [--thinking] [--session|--no-session]`.

Gaps:

- Re-verify JSONL against current `docs/json.md`. Newer Pi emits `message_update` deltas; `message_end` is authoritative. The ephemeral `turn_end` fallback should stay.
- `--mode rpc` is a different integration (long-lived process). Not needed for 2.0; useful later for connection reuse.
- Forward `PI_SKIP_VERSION_CHECK=1` in CI-style env builds.
- Provider list: Anthropic / OpenAI / Gemini today; do not pretend Pi is a Grok driver.

---

## Phase 4 — New drivers: Cursor and Grok

Same `CliDriver` + `DriverOptions` + JSON fixture pattern as the others. Register as `cursor-cli` and `grok-cli`.

### Cursor (`agent`, alias `cursor-agent`)

Headless shape:

```bash
agent -p "prompt" --output-format json --model <model> --workspace <dir> --trust --force
```

| Concern | Mapping |
| --- | --- |
| Binary | `agent` (document `cursor-agent` as fallback in `ExecutableFinder`) |
| Auth | `CURSOR_API_KEY` / `--api-key` |
| Resume | `--resume=<chatId>` or `--continue` |
| Modes | `--mode=plan` / `--mode=ask` (default agent) |
| Sandbox | `--sandbox enabled\|disabled` |
| Workspace | `--workspace` (also set process cwd) |
| Auto-approve | `--force` / `--yolo` — **opt-in**, same policy as Amp/Codex |
| MCP | `--approve-mcps` + `--plugin-dir` |
| Streaming | `--output-format stream-json` (+ `--stream-partial-output` later) |
| JSON | Terminal `{type:"result", subtype:"success", result, session_id, duration_ms}` — close to Claude’s result event; a shared `fromClaudeCompatibleResult()` parser is reasonable |

Print mode has write + shell tools. README security note must cover Cursor the same way it covers Codex/Amp.

### Grok Build (`grok`)

Headless shape:

```bash
grok --no-auto-update -p "prompt" --output-format json --model <model> --cwd <dir> --always-approve
```

| Concern | Mapping |
| --- | --- |
| Binary | `grok` |
| Auth | `XAI_API_KEY` (device-code `grok login` is interactive; headless needs the key) |
| Resume | `-s/--session-id`, `-r/--resume`, `-c/--continue` |
| Model | `-m` (default something current, e.g. `grok-4.6`, from config) |
| Turns | `--max-turns` |
| Schema | `--json-schema` |
| Streaming | `--output-format streaming-json` (note the name: **streaming-json**, not stream-json) |
| Auto-approve | `--always-approve` — opt-in |
| CI | `--no-auto-update` always in the subprocess |
| JSON | Final object includes `sessionId`, usage, and cost (changelog: token usage + cost per prompt/session) |

Do not confuse this with npm `grok-agent`; the first-party binary is Grok Build.

---

## Phase 5 — Streaming, schema, and process I/O

Once all six drivers print JSON:

1. **Structured output.** If `DriverOptions.jsonSchema` or the SDK `$schema` is set, pass `--json-schema` (Claude, Grok) or `--output-schema` (Codex). Pi/Amp/Cursor: document as unsupported or emulate via prompt-only with a warning (no silent drop).
2. **Streaming.** Symfony `Process` with incremental stdout. Parse JSONL per driver; map to `generateStreamStep()` / `StreamEvent`. Claude, Amp, Cursor, Grok, Codex already have JSONL. Pi JSON mode is already a stream.
3. **Working directory + extra roots.** Honor `DriverOptions.workingDirectory` on every driver (Claude currently relies on process cwd only; Codex has `-C`; Cursor `--workspace`; Grok `--cwd`).
4. **Version probe.** `isInstalled()` plus `version(): ?string`. Warn when below `config.minimum_version`.
5. **Cost.** Claude and Grok report USD. Codex/Cursor/Amp often do not — keep `0.00` and document it. Never invent a price.

ACP (`agent acp`, `grok agent stdio`, Pi `--mode rpc`) is a follow-on project: one long-lived process, JSON-RPC, different timeout/lifecycle. Do not fold it into `AbstractCliDriver::runProcess()`.

---

## Capability matrix (2.0 target)

| | Resume | Tools allow/deny | MCP | System prompt flag | JSON schema | Streaming JSON | Session cost |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Claude | `--resume` / `--continue` | `--allowedTools`, `--disallowedTools`, `--tools` | `--mcp-config` | `--system-prompt[-file]`, append variants | `--json-schema` | `--output-format stream-json` | `total_cost_usd` |
| Codex | `exec resume [id\|--last]` | sandbox / full-auto (not a tool list) | no first-class flag today | prepend into prompt | `--output-schema` | `--json` JSONL | usually none |
| Amp | `threads continue <id>` | `--dangerously-allow-all` (blunt) | `--mcp-config` | prepend into prompt | no | `--stream-json` | duration only |
| Pi | `--session` / `--no-session` | `--tools` | no first-class flag | `--system-prompt` | no | `--mode json` | when usage present |
| Cursor | `--resume` / `--continue` | `--force`, `--sandbox` | `--approve-mcps` | prepend / `--mode` | no (watch docs) | `--output-format stream-json` | duration; watch for usage |
| Grok | `-s` / `-r` / `-c` | `--always-approve` | MCP via grok config | `--system-prompt-file` (CLI ref) | `--json-schema` | `--output-format streaming-json` | usage + cost |

---

## Suggested sequence

Ship as stacked PRs on a `2.x` branch, not one megadiff.

1. **0.x hygiene** — CI, fixtures, CONTRIBUTING matrix note.
2. **2.0.0-alpha** — `laravel/ai` 0.10 gateway rewrite + Laravel 12/13 constraints. Existing four drivers still work, tests green on Testbench 10 and 11.
3. **Types** — `DriverOptions`, PHPStan 9 then 10, tests in the analysed paths.
4. **Claude / Codex / Amp / Pi flag parity** — especially Amp resume and safer auto-approve defaults.
5. **CursorDriver + GrokDriver** — config, provider IDs, parsers, README table.
6. **2.0.0** — streaming + schema forwarding. Tag when PHPStan 10 is clean and the six-driver table in README is true.

Optional later: Pest 5 + PHP `^8.4`, drop Laravel 12, ACP transport, driver auto-discovery of binaries with version constraints.

---

## Risks

- **`laravel/ai` is still 0.x.** 0.11 can move `StepTextGateway` again. Pin `^0.10.3` and add deprecation-rules so the break is loud. Revisit a `~0.10.3` pin if 0.11 lands during the alpha.
- **CLI JSON is not a stable ecosystem schema.** Amp claims Claude-compatibility; Cursor’s result event is similar; Grok uses `sessionId` camelCase. Parsers must be per-driver with fixtures, not one “universal JSONL” function.
- **Headless permission prompts hang PHP.** Any driver without `--force` / `--always-approve` / `--permission-mode bypassPermissions` / `--full-auto` can block `Process::run()` until timeout. Timeouts already exist; 2.0 should fail fast with a dedicated “permissions would block headless” config error when auto-approve is off and the CLI has no non-interactive fallback.
- **Binary name collisions.** `pi` and `agent` are generic. Absolute `binary` paths in config are already supported — document that as the production setting.
- **Secrets in argv.** Prompts and system prompts appear in process argument lists. Claude’s temp-file system prompt is the right pattern; prefer stdin / prompt files where a CLI allows it (Amp `--stream-json-input`, Claude `--input-format stream-json`).
- **No CI today** means the 0.10 rewrite will be the first time Testbench actually boots `ConduitServiceProvider` against a real `AiManager`. Budget time for container / facade issues that unit tests on `new ConduitGateway($fake)` never see.

---

## Out of scope for 2.0

- Embedding, image, audio, transcription (CLI agents are text/tool loops).
- Cloud-hosted agents (Cursor cloud, Claude `--cloud`, Grok dashboard) as first-class transports.
- Implementing Laravel AI tools inside the CLI subprocess (`$tools` on the SDK). The CLI owns tools; the SDK should not pretend otherwise. Schema forwarding is the exception because several CLIs now have a native JSON-schema flag.
- A plugin marketplace or MCP server inside Conduit. Pass config through; do not start MCP processes.
- Windows-specific argument escaping beyond what Symfony Process already does — test on Linux/macOS first.

---

## README / config changes that land with 2.0

- Requirements: PHP 8.3+, Laravel 12 or 13, `laravel/ai` 0.10.x, six optional binaries.
- Provider IDs: add `cursor-cli`, `grok-cli`.
- Security paragraph: Cursor `--force` and Grok `--always-approve` join Codex `--full-auto` and Amp `--dangerously-allow-all`. Published config defaults to **not** passing those flags.
- Default models live in `config/conduit.php` only, with a comment that aliases (`sonnet`, `grok-4.6`, `gpt-5.3-codex`) track vendor CLIs and will move.
- CONTRIBUTING: PHPStan level 10, CI required, fixture updates when bumping a parser.
