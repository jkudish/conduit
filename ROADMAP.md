# Conduit Modernization Roadmap

Research snapshot: **20 August 2026**. Execution tickets: [ISSUES.md](ISSUES.md). Do not implement this as one PR.

Conduit is a Laravel AI SDK gateway that shells out to coding-agent CLIs. The package is still on Laravel 12 + `laravel/ai` 0.5.1, talks to four CLIs (Claude Code, Codex, Amp, Pi), and already runs PHPStan 8 via Larastan.

## Decisions (20 Aug 2026)

- **Six issues, six PRs.** Foundation → DriverOptions → PHPStan 9/10 → CLI parity → Cursor/Grok → streaming. See [ISSUES.md](ISSUES.md).
- **Pest 5** is in scope for 2.0. That forces **PHP `^8.4`** and **Laravel 13** (`pest-plugin-laravel` 5.0.1 requires `laravel/framework: ^13.23`). Drop Laravel 12 / PHP 8.3.
- **No GitHub Actions.** Actions is out of credits; ignore red CI. Gate on local `composer test`, `composer phpstan`, `composer lint:check`. JSON fixtures still belong in `tests/Fixtures/` for local Pest.
- **PHPStan stays at 8** through the `laravel/ai` rewrite. Then 9 after DriverOptions. Then 10. Do not start at 10. Analysis below.

---

## Current vs target

| Layer | Today | Target |
| --- | --- | --- |
| PHP | `^8.3` | `^8.4` (Pest 5) |
| Laravel | `illuminate/* ^12.0` | `^13.0` |
| AI SDK | `laravel/ai ^0.5.1` | `^0.10.3` |
| Testbench | `^10.0` | `^11.0` |
| Pest | `^4.0` | `^5.0` + `pest-plugin-laravel ^5.0` |
| PHPStan | `^2.0`, **level 8**, `src/` only | PHPStan `^2.2`, Larastan `^3.10`, **level 8 → 9 → 10** |
| Drivers | Claude, Codex, Amp, Pi | + Cursor (`agent`), + Grok (`grok`) |
| Streaming | Throws; README says “v2” | `stream-json` / Grok `streaming-json` |
| Structured output | SDK `$schema` ignored | Forward `--json-schema` / `--output-schema` |
| CI | None (and stay that way) | Local Pest + Pint + PHPStan only |

`laravel/ai` 0.10 still allows Laravel 12 / PHP 8.3. **Pest 5 is what raises the floor.** Laravel 13 shipped 17 March 2026. Orchestra Testbench 11 and Larastan 3.10 already support it.

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

Drop Laravel 12 in 2.0 along with PHP 8.3. Pest 5 is not optional.

---

## Principles

1. **CLIs are the product; Laravel is the adapter.** Drivers should track documented headless flags, not try to be a generic LLM client.
2. **One typed options object, six command builders.** Stop growing `ConduitContext` + untyped `$options` in parallel.
3. **Capabilities, not identical flags.** Resume, MCP, tools, schema, and streaming are per-driver. The gateway maps SDK intent onto what the binary actually has.
4. **Safe defaults for subprocesses.** Auto-approve / skip-sandbox is explicit config, never the published default.
5. **Fixture-first parsers.** Each JSON/JSONL dialect lives in golden files captured from a real CLI version. Drivers pin a minimum CLI version in config and README.
6. **Do not invent ACP yet.** Cursor and Grok expose Agent Client Protocol. That is a third integration style (JSON-RPC stdio), not a CLI driver. Park it until the six print-mode drivers are current.

---

## Phase 0 — Local quality only (folded into issue 1)

Do **not** add GitHub Actions. Fold these into the foundation PR:

- `composer test`, `composer phpstan`, `composer lint:check` must pass locally.
- Add `Pest.php` / Testbench `TestCase` if the 0.10 rewrite needs a booted app for `Context` / `Log`.
- Move parser JSON into `tests/Fixtures/{claude,codex,amp,pi}/`.
- Document minimum CLI versions in README when a parser is touched, not as its own project.

---

## Phase 1 — Laravel 13 + `laravel/ai` 0.10

This is the load-bearing phase. Everything else rebases onto it.

### Composer

```json
"php": "^8.4",
"illuminate/support": "^13.0",
"illuminate/contracts": "^13.0",
"laravel/ai": "^0.10.3",
"symfony/process": "^7.4|^8.0"
```

Dev:

```json
"larastan/larastan": "^3.10",
"orchestra/testbench": "^11.0",
"pestphp/pest": "^5.0",
"pestphp/pest-plugin-laravel": "^5.0",
"phpstan/phpstan": "^2.2"
```

`pest-plugin-laravel` 5.0.1 requires `laravel/framework: ^13.23`. Testbench 11 already pulls that. Enable `allow-plugins.pestphp/pest-plugin`.

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

## PHPStan: 8 vs 9 vs 10

**Recommendation: stay on 8 for issue 1, 9 after DriverOptions, 10 as its own follow-up. Do not start at 10.**

PHPStan 2.x levels that matter here (cumulative):

| Level | What it adds | Conduit today |
| --- | --- | --- |
| 6 | Missing typehints | Already clean — constructors and methods are typed |
| 7 | Union-type holes | Fine |
| **8** | Calling methods / accessing properties on **nullable** | **Current gate.** `?string` + casts. Keep this through the SDK rewrite. |
| **9** | Strict **explicit `mixed`** — you may only pass `mixed` to another `mixed` | The option bags and JSON parsers |
| **10** | Same rules for **implicit `mixed`** (missing types, untyped foreach values, untyped `config()` / `Context::get()`) | Parser/array-shape work |

Level 8 is the right *current* setting. The `laravel/ai` 0.10 rewrite will move `Gateway` → `StepTextGateway`, `TextResponse` → `StepResponse`, and `Meta`’s constructor. That is real type work. Stacking level 9/10 on the same PR mixes SDK-break noise with mixed-strictness noise.

### What level 9 actually hits in this repo

Level 9 does not mean “add more `@var`”. It means: once a value is `mixed`, you cannot offset it, call methods on it, or pass it to `string`/`int`/`array` without narrowing.

Hot spots:

1. **`array<string, mixed> $options`** on every `execute` / `buildCommand`. `$options['model']` is `mixed`. Drivers already `(string)`-cast, which is usually enough for 9, but the public contract is still a junk drawer. **DriverOptions removes this entire class of errors.** Doing 9 before the DTO is wasted motion.
2. **`CliRunResult` JSON parsers.** `json_decode()` is `mixed`. `findResultEvent(mixed $decoded)` already narrows with `is_array()`. The pain is `foreach ($content as $block)` and `$event['item']` — values of `array<string, mixed>` are `mixed`, so `$item['type']` is a level-9 error unless you narrow or `@var` an array shape. There are already several `@var array<string, mixed>` annotations; 9 will tell you which ones are missing.
3. **`extractPromptFromMessages(array $messages)`** typed as `array<int, mixed>`. The `is_object($m) ? $m::class` callback is already narrowed. Fine at 9 if `UserMessage` is tested with `instanceof`.

Level 9 after DriverOptions should be a short PR: leftover JSON offset access in `CliRunResult`, maybe `config()` in the service provider. **No baseline.** If 9 needs a baseline, the DTO did not actually land.

### What level 10 adds

Implicit mixed is “you forgot a type”, not “you wrote `mixed`”. After 9:

- `config('conduit.drivers.claude', [])` and `Context::get()` return mixed unless Larastan/the call is annotated. The service provider already `@var array<string, mixed>` on config blobs — 10 wants that to be a real config DTO or a shaped array.
- `foreach ($content as $block)` when `$content` is `array` with no value type.
- Analysing `tests/` at 10 is noisy (Mockery, Pest closures). Keep phpstan on `src/` until 10 is boring.

Level 10 is the right *end state* for a small package. It is the wrong *first* move. `phpstan-strict-rules` and `bleedingEdge.neon` wait until 10 is green.

### What is already good

`declare(strict_types=1)`, promoted properties, `list<string>` PHPDoc, readonly `CliRunResult` / `CliProcessResult`. Do not churn those.

### DriverOptions (issue 2, still level 8)

Replace the option bag with a readonly DTO. `ConduitContext` builds it. `CliDriver::execute(string $prompt, DriverOptions $options)`. Optional `DriverCapability` set so `$tools` / `$schema` are not silently ignored. Shape JSON events later (issue 3), not in the DTO PR.

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

One GitHub issue per PR. Copy from [ISSUES.md](ISSUES.md).

1. `laravel/ai` 0.10 + Laravel 13 + Pest 5 (PHP 8.4). PHPStan stays 8. Local tests only. Parser fixtures for the four existing CLIs.
2. `DriverOptions` DTO. Still PHPStan 8.
3. PHPStan 9, then 10. No baseline.
4. Claude / Codex / Amp / Pi flag parity (Amp `threads continue`, opt-in auto-approve).
5. Cursor (`agent`) + Grok (`grok`) drivers.
6. Streaming JSONL + schema forwarding. ACP stays parked.

Optional later: ACP transport, binary version probes, `phpstan-strict-rules`.

---

## Risks

- **`laravel/ai` is still 0.x.** 0.11 can move `StepTextGateway` again. Pin `^0.10.3` and add deprecation-rules so the break is loud. Revisit a `~0.10.3` pin if 0.11 lands during the alpha.
- **CLI JSON is not a stable ecosystem schema.** Amp claims Claude-compatibility; Cursor’s result event is similar; Grok uses `sessionId` camelCase. Parsers must be per-driver with fixtures, not one “universal JSONL” function.
- **Headless permission prompts hang PHP.** Any driver without `--force` / `--always-approve` / `--permission-mode bypassPermissions` / `--full-auto` can block `Process::run()` until timeout. Timeouts already exist; 2.0 should fail fast with a dedicated “permissions would block headless” config error when auto-approve is off and the CLI has no non-interactive fallback.
- **Binary name collisions.** `pi` and `agent` are generic. Absolute `binary` paths in config are already supported — document that as the production setting.
- **Secrets in argv.** Prompts and system prompts appear in process argument lists. Claude’s temp-file system prompt is the right pattern; prefer stdin / prompt files where a CLI allows it (Amp `--stream-json-input`, Claude `--input-format stream-json`).
- **No CI (intentional).** The 0.10 rewrite will be the first time Testbench actually boots `ConduitServiceProvider` against a real `AiManager`. Budget time for container / facade issues that unit tests on `new ConduitGateway($fake)` never see. Run Pest locally; ignore red GitHub Actions.

---

## Out of scope for 2.0

- Embedding, image, audio, transcription (CLI agents are text/tool loops).
- Cloud-hosted agents (Cursor cloud, Claude `--cloud`, Grok dashboard) as first-class transports.
- Implementing Laravel AI tools inside the CLI subprocess (`$tools` on the SDK). The CLI owns tools; the SDK should not pretend otherwise. Schema forwarding is the exception because several CLIs now have a native JSON-schema flag.
- A plugin marketplace or MCP server inside Conduit. Pass config through; do not start MCP processes.
- Windows-specific argument escaping beyond what Symfony Process already does — test on Linux/macOS first.

---

## README / config changes that land with 2.0

- Requirements: PHP 8.4+, Laravel 13, `laravel/ai` 0.10.x, six optional binaries.
- Provider IDs: add `cursor-cli`, `grok-cli`.
- Security paragraph: Cursor `--force` and Grok `--always-approve` join Codex `--full-auto` and Amp `--dangerously-allow-all`. Published config defaults to **not** passing those flags.
- Default models live in `config/conduit.php` only, with a comment that aliases (`sonnet`, `grok-4.6`, `gpt-5.3-codex`) track vendor CLIs and will move.
- CONTRIBUTING: PHPStan level 10, CI required, fixture updates when bumping a parser.
