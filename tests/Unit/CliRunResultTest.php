<?php

declare(strict_types=1);

use Conduit\DTOs\CliRunResult;
use Conduit\Exceptions\CliParseException;

it('parses claude cli json result event', function (): void {
    $json = json_encode([
        'type' => 'result',
        'subtype' => 'success',
        'session_id' => 'session-abc',
        'result' => 'Hello world',
        'total_cost_usd' => 0.05,
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        'duration_ms' => 1500,
        'num_turns' => 2,
        'is_error' => false,
    ]);

    $result = CliRunResult::fromClaudeJson($json, ['model' => 'claude-sonnet-4-5']);

    expect($result->sessionId)->toBe('session-abc')
        ->and($result->result)->toBe('Hello world')
        ->and($result->costUsd)->toBe(0.05)
        ->and($result->inputTokens)->toBe(100)
        ->and($result->outputTokens)->toBe(50)
        ->and($result->durationMs)->toBe(1500)
        ->and($result->numTurns)->toBe(2)
        ->and($result->isError)->toBeFalse()
        ->and($result->model)->toBe('claude-sonnet-4-5');
});

it('parses claude cli json array format', function (): void {
    $json = json_encode([
        ['type' => 'system', 'subtype' => 'init', 'session_id' => 'session-xyz'],
        ['type' => 'assistant', 'message' => ['content' => 'thinking...']],
        ['type' => 'result', 'subtype' => 'success', 'session_id' => 'session-xyz', 'result' => 'Done', 'usage' => ['input_tokens' => 200, 'output_tokens' => 100], 'duration_ms' => 3000, 'num_turns' => 3, 'is_error' => false, 'total_cost_usd' => 0.10],
    ]);

    $result = CliRunResult::fromClaudeJson($json, ['model' => 'test-model']);

    expect($result->sessionId)->toBe('session-xyz')
        ->and($result->result)->toBe('Done')
        ->and($result->inputTokens)->toBe(200)
        ->and($result->outputTokens)->toBe(100);
});

it('resolves model from modelUsage keys when not in overrides', function (): void {
    $json = json_encode([
        'type' => 'result',
        'session_id' => 'sess-1',
        'result' => 'ok',
        'modelUsage' => ['claude-opus-4' => ['input' => 10, 'output' => 20]],
    ]);

    $result = CliRunResult::fromClaudeJson($json);

    expect($result->model)->toBe('claude-opus-4');
});

it('throws on invalid json for claude format', function (): void {
    CliRunResult::fromClaudeJson('not-json');
})->throws(CliParseException::class, 'Failed to parse CLI output as JSON');

it('throws on missing result event for claude format', function (): void {
    $json = json_encode([
        ['type' => 'system', 'subtype' => 'init'],
        ['type' => 'assistant', 'message' => 'hi'],
    ]);

    CliRunResult::fromClaudeJson($json);
})->throws(CliParseException::class, 'No "type": "result" event found');

it('parses codex jsonl output', function (): void {
    $json = implode("\n", [
        '{"type":"thread.started","thread_id":"thread-abc-123"}',
        '{"type":"turn.started"}',
        '{"type":"item.completed","item":{"id":"item_0","type":"reasoning","text":"thinking"}}',
        '{"type":"item.completed","item":{"id":"item_1","type":"agent_message","text":"Hello from Codex!"}}',
        '{"type":"turn.completed","usage":{"input_tokens":1000,"cached_input_tokens":0,"output_tokens":42}}',
    ]);

    $result = CliRunResult::fromCodexJson($json, ['model' => 'gpt-5.3-codex']);

    expect($result->sessionId)->toBe('thread-abc-123')
        ->and($result->result)->toBe('Hello from Codex!')
        ->and($result->inputTokens)->toBe(1000)
        ->and($result->outputTokens)->toBe(42)
        ->and($result->model)->toBe('gpt-5.3-codex')
        ->and($result->costUsd)->toBe(0.0);
});

it('parses amp jsonl output', function (): void {
    $json = implode("\n", [
        '{"type":"system","subtype":"init","session_id":"T-session-amp-1","tools":["Bash","Read"],"cwd":"/tmp"}',
        '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"hello"}]}}',
        '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"Hi from Amp!"}],"usage":{"input_tokens":500,"output_tokens":30,"cache_creation_input_tokens":1000}}}',
        '{"type":"result","subtype":"success","duration_ms":2000,"is_error":false,"num_turns":1,"result":"Hi from Amp!","session_id":"T-session-amp-1"}',
    ]);

    $result = CliRunResult::fromAmpJson($json, ['model' => 'smart']);

    expect($result->sessionId)->toBe('T-session-amp-1')
        ->and($result->result)->toBe('Hi from Amp!')
        ->and($result->inputTokens)->toBe(500)
        ->and($result->outputTokens)->toBe(30)
        ->and($result->durationMs)->toBe(2000)
        ->and($result->numTurns)->toBe(1)
        ->and($result->model)->toBe('smart');
});

it('throws on empty codex output', function (): void {
    CliRunResult::fromCodexJson('');
})->throws(CliParseException::class, 'No valid JSON lines found');

it('throws on missing amp result event', function (): void {
    $json = '{"type":"system","subtype":"init","session_id":"T-1"}';

    CliRunResult::fromAmpJson($json);
})->throws(CliParseException::class, 'No "type": "result" event found in Amp output');

it('parses pi jsonl output', function (): void {
    $json = implode("\n", [
        '{"type":"session","version":3,"id":"pi-session-abc-123","timestamp":"2026-03-29T12:00:00Z","cwd":"/tmp"}',
        '{"type":"agent_start","timestamp":"2026-03-29T12:00:01Z"}',
        '{"type":"turn_start","timestamp":"2026-03-29T12:00:02Z"}',
        '{"type":"message_start","timestamp":"2026-03-29T12:00:03Z","message":{"role":"assistant","content":[]}}',
        '{"type":"message_update","timestamp":"2026-03-29T12:00:04Z","responseId":"resp-1","usage":{"input":1200,"output":200,"cacheRead":50,"cacheWrite":25,"cost":{"total":0.34}},"message":{"role":"assistant"},"assistantMessageEvent":{"type":"text_delta","delta":"Hello"}}',
        '{"type":"message_end","timestamp":"2026-03-29T12:00:05Z","message":{"role":"assistant","content":[{"type":"text","text":"Hello from Pi!"}]}}',
        '{"type":"turn_end","timestamp":"2026-03-29T12:00:06Z","message":{"role":"assistant"},"toolResults":[]}',
        '{"type":"agent_end","timestamp":"2026-03-29T12:00:07Z","messages":[{"role":"user","content":[{"type":"text","text":"Write hello world"}]},{"role":"assistant","content":[{"type":"text","text":"Hello from Pi!"}]}]}',
    ]);

    $result = CliRunResult::fromPiJson($json, ['model' => 'claude-sonnet-4-5']);

    expect($result->sessionId)->toBe('pi-session-abc-123')
        ->and($result->result)->toBe('Hello from Pi!')
        ->and($result->costUsd)->toBe(0.34)
        ->and($result->inputTokens)->toBe(1275)
        ->and($result->outputTokens)->toBe(200)
        ->and($result->durationMs)->toBe(7000)
        ->and($result->numTurns)->toBe(1)
        ->and($result->isError)->toBeFalse()
        ->and($result->model)->toBe('claude-sonnet-4-5');
});

it('parses pi output with multiple text blocks', function (): void {
    $json = implode("\n", [
        '{"type":"session","version":3,"id":"pi-multi-block","timestamp":"2026-03-29T12:00:00Z","cwd":"/tmp"}',
        '{"type":"turn_start"}',
        '{"type":"turn_end","message":{"role":"assistant"},"toolResults":[]}',
        '{"type":"turn_start"}',
        '{"type":"turn_end","message":{"role":"assistant"},"toolResults":[]}',
        '{"type":"agent_end","messages":[{"role":"assistant","content":[{"type":"text","text":"Part one"},{"type":"text","text":"Part two"}]}]}',
    ]);

    $result = CliRunResult::fromPiJson($json);

    expect($result->sessionId)->toBe('pi-multi-block')
        ->and($result->result)->toBe("Part one\nPart two")
        ->and($result->numTurns)->toBe(2);
});

it('parses pi output with mixed content blocks', function (): void {
    $json = implode("\n", [
        '{"type":"session","version":3,"id":"pi-mixed","timestamp":"2026-03-29T12:00:00Z","cwd":"/tmp"}',
        '{"type":"turn_start"}',
        '{"type":"turn_end","message":{"role":"assistant"},"toolResults":[]}',
        '{"type":"agent_end","messages":[{"role":"assistant","content":[{"type":"tool_use","id":"tool_1","name":"bash"},{"type":"text","text":"Done!"}]}]}',
    ]);

    $result = CliRunResult::fromPiJson($json);

    expect($result->result)->toBe('Done!')
        ->and($result->numTurns)->toBe(1);
});

it('throws on empty pi output', function (): void {
    CliRunResult::fromPiJson('');
})->throws(CliParseException::class, 'No valid JSON lines found');

it('throws on pi output with no session or agent_end events', function (): void {
    $json = '{"type":"agent_start"}';

    CliRunResult::fromPiJson($json);
})->throws(CliParseException::class, 'No session or agent_end events found in Pi output');

it('parses pi ephemeral output without agent_end (turn_end fallback)', function (): void {
    $json = implode("\n", [
        '{"type":"session","version":3,"id":"pi-ephemeral-1","timestamp":"2026-04-12T23:44:49Z","cwd":"/tmp"}',
        '{"type":"agent_start"}',
        '{"type":"turn_start"}',
        '{"type":"message_start","message":{"role":"user","content":[{"type":"text","text":"Say PONG"}]}}',
        '{"type":"message_end","message":{"role":"user","content":[{"type":"text","text":"Say PONG"}]}}',
        '{"type":"message_start","message":{"role":"assistant","content":[]}}',
        '{"type":"message_end","message":{"role":"assistant","content":[{"type":"thinking","thinking":"thinking..."},{"type":"text","text":"PONG"}]}}',
        '{"type":"turn_end","message":{"role":"assistant","content":[{"type":"thinking","thinking":"thinking..."},{"type":"text","text":"PONG"}]},"toolResults":[]}',
    ]);

    $result = CliRunResult::fromPiJson($json, ['model' => 'claude-sonnet-4-5']);

    expect($result->sessionId)->toBe('pi-ephemeral-1')
        ->and($result->result)->toBe('PONG')
        ->and($result->numTurns)->toBe(1)
        ->and($result->isError)->toBeFalse();
});

it('parses pi ephemeral output with message_end fallback only', function (): void {
    $json = implode("\n", [
        '{"type":"session","version":3,"id":"pi-ephemeral-2","timestamp":"2026-04-12T23:44:49Z","cwd":"/tmp"}',
        '{"type":"agent_start"}',
        '{"type":"turn_start"}',
        '{"type":"message_end","message":{"role":"assistant","content":[{"type":"text","text":"Hello from Pi!"}]}}',
    ]);

    $result = CliRunResult::fromPiJson($json);

    expect($result->result)->toBe('Hello from Pi!');
});

it('skips malformed jsonl lines gracefully', function (): void {
    $json = implode("\n", [
        '{"type":"thread.started","thread_id":"t-1"}',
        'not valid json',
        '[=0u[<u[?25h', // terminal escape sequences
        '{"type":"item.completed","item":{"type":"agent_message","text":"ok"}}',
        '{"type":"turn.completed","usage":{"input_tokens":10,"output_tokens":5}}',
    ]);

    $result = CliRunResult::fromCodexJson($json);

    expect($result->sessionId)->toBe('t-1')
        ->and($result->result)->toBe('ok');
});
