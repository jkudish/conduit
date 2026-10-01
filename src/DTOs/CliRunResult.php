<?php

declare(strict_types=1);

namespace Conduit\DTOs;

use Conduit\Exceptions\CliParseException;
use Illuminate\Support\Facades\Log;

final readonly class CliRunResult
{
    public function __construct(
        public string $sessionId,
        public string $result,
        public float $costUsd,
        public int $inputTokens,
        public int $outputTokens,
        public int $durationMs,
        public int $numTurns,
        public bool $isError,
        public string $model,
    ) {}

    /**
     * Parse a CliRunResult from Claude CLI JSON output.
     *
     * Claude CLI `--output-format json` returns a JSON array of events:
     * - {"type": "system", "subtype": "init", ...} — session metadata
     * - {"type": "assistant", "message": {...}} — assistant responses
     * - {"type": "result", "subtype": "success", ...} — final result
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws CliParseException
     */
    public static function fromClaudeJson(string $json, array $overrides = []): self
    {
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw CliParseException::invalidJson($json, json_last_error_msg());
        }

        $resultEvent = self::findResultEvent($decoded, $json);

        /** @var array{input_tokens?: int, output_tokens?: int, cache_creation_input_tokens?: int, cache_read_input_tokens?: int}|null $usage */
        $usage = $resultEvent['usage'] ?? null;
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $inputTokens += (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $inputTokens += (int) ($usage['cache_read_input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        $model = (string) ($overrides['model'] ?? '');
        if ($model === '' && isset($resultEvent['modelUsage']) && is_array($resultEvent['modelUsage'])) {
            /** @var array<string, mixed> $modelUsage */
            $modelUsage = $resultEvent['modelUsage'];
            $modelKeys = array_keys($modelUsage);
            $model = (string) ($modelKeys[0] ?? '');
        }

        return new self(
            sessionId: (string) ($resultEvent['session_id'] ?? ''),
            result: (string) ($resultEvent['result'] ?? ''),
            costUsd: (float) ($resultEvent['total_cost_usd'] ?? 0.0),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            durationMs: (int) ($resultEvent['duration_ms'] ?? 0),
            numTurns: (int) ($resultEvent['num_turns'] ?? 0),
            isError: (bool) ($resultEvent['is_error'] ?? false),
            model: $model,
        );
    }

    /**
     * Parse a CliRunResult from Codex CLI JSON output.
     *
     * Codex `exec --json` outputs JSONL events:
     * - {"type": "thread.started", "thread_id": "..."} — session metadata
     * - {"type": "item.completed", "item": {"type": "agent_message", "text": "..."}}
     * - {"type": "turn.completed", "usage": {...}}
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws CliParseException
     */
    public static function fromCodexJson(string $json, array $overrides = []): self
    {
        $events = self::parseJsonLines($json);

        $threadId = '';
        $resultText = '';
        $inputTokens = 0;
        $outputTokens = 0;

        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');

            if ($type === 'thread.started') {
                $threadId = (string) ($event['thread_id'] ?? '');
            }

            if ($type === 'item.completed' && isset($event['item'])) {
                /** @var array<string, mixed> $item */
                $item = $event['item'];
                if (($item['type'] ?? '') === 'agent_message') {
                    $resultText = (string) ($item['text'] ?? '');
                }
            }

            if ($type === 'turn.completed' && isset($event['usage'])) {
                /** @var array{input_tokens?: int, output_tokens?: int, cached_input_tokens?: int} $usage */
                $usage = $event['usage'];
                $inputTokens += (int) ($usage['input_tokens'] ?? 0);
                $outputTokens += (int) ($usage['output_tokens'] ?? 0);
            }
        }

        if ($resultText === '' && $threadId === '') {
            throw CliParseException::unexpectedFormat(
                'No thread.started or agent_message events found in Codex output',
                $json,
            );
        }

        if ($resultText === '') {
            throw CliParseException::unexpectedFormat(
                'No agent_message event found in Codex output',
                $json,
            );
        }

        return new self(
            sessionId: $threadId,
            result: $resultText,
            costUsd: 0.0,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            durationMs: 0,
            numTurns: 1,
            isError: false,
            model: (string) ($overrides['model'] ?? ''),
        );
    }

    /**
     * Parse a CliRunResult from Amp CLI JSON output.
     *
     * Amp `-x --stream-json` outputs Claude Code-compatible JSONL events:
     * - {"type": "system", "subtype": "init", "session_id": "..."}
     * - {"type": "assistant", "message": {"content": [...]}}
     * - {"type": "result", "subtype": "success", ...}
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws CliParseException
     */
    public static function fromAmpJson(string $json, array $overrides = []): self
    {
        // Amp uses Claude Code-compatible format, so we can use the same parser
        // but handle the slight differences in usage location
        $events = self::parseJsonLines($json);

        $resultEvent = null;
        $inputTokens = 0;
        $outputTokens = 0;

        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');

            if ($type === 'result') {
                $resultEvent = $event;
            }

            // Amp puts usage in the assistant message, not the result
            if ($type === 'assistant' && isset($event['message']['usage'])) {
                /** @var array{input_tokens?: int, output_tokens?: int, cache_creation_input_tokens?: int, cache_read_input_tokens?: int} $usage */
                $usage = $event['message']['usage'];
                $inputTokens += (int) ($usage['input_tokens'] ?? 0);
                $inputTokens += (int) ($usage['cache_creation_input_tokens'] ?? 0);
                $inputTokens += (int) ($usage['cache_read_input_tokens'] ?? 0);
                $outputTokens += (int) ($usage['output_tokens'] ?? 0);
            }
        }

        if ($resultEvent === null) {
            throw CliParseException::unexpectedFormat(
                'No "type": "result" event found in Amp output',
                $json,
            );
        }

        return new self(
            sessionId: (string) ($resultEvent['session_id'] ?? ''),
            result: (string) ($resultEvent['result'] ?? ''),
            costUsd: 0.0,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            durationMs: (int) ($resultEvent['duration_ms'] ?? 0),
            numTurns: (int) ($resultEvent['num_turns'] ?? 0),
            isError: (bool) ($resultEvent['is_error'] ?? false),
            model: (string) ($overrides['model'] ?? ''),
        );
    }

    /**
     * Parse a CliRunResult from Pi coding agent JSON output.
     *
     * Pi `--mode json` outputs JSONL events:
     * - {"type": "session", "id": "...", "version": 3} — session metadata
     * - {"type": "turn_end", ...} — one per agent turn
     * - {"type": "agent_end", "messages": [...]} — final messages array
     *
     * The last assistant message in agent_end contains the result text.
     * When available, Pi usage data is aggregated from per-message usage blocks.
     *
     * @param  array<string, mixed>  $overrides
     *
     * @throws CliParseException
     */
    public static function fromPiJson(string $json, array $overrides = []): self
    {
        $events = self::parseJsonLines($json);

        $sessionId = '';
        $resultText = '';
        $numTurns = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $costUsd = 0.0;
        $durationMs = 0;
        $firstTimestamp = null;
        $lastTimestamp = null;
        $usageFound = false;
        $seenUsageKeys = [];

        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');

            if ($type === 'session') {
                $sessionId = (string) ($event['id'] ?? '');
            }

            $timestamp = self::extractTimestamp($event);
            if ($timestamp !== null) {
                $firstTimestamp = $firstTimestamp === null ? $timestamp : min($firstTimestamp, $timestamp);
                $lastTimestamp = $lastTimestamp === null ? $timestamp : max($lastTimestamp, $timestamp);
            }

            if ($type === 'turn_end') {
                $numTurns++;
            }

            $usage = self::extractUsage($event);
            if ($usage !== null) {
                $usageKey = self::resolveUsageKey($event);

                if ($usageKey === null || ! isset($seenUsageKeys[$usageKey])) {
                    if ($usageKey !== null) {
                        $seenUsageKeys[$usageKey] = true;
                    }

                    [$inputTokens, $outputTokens, $costUsd] = self::accumulateUsage(
                        $usage,
                        $inputTokens,
                        $outputTokens,
                        $costUsd,
                    );
                    $usageFound = true;
                }
            }

            if ($type === 'agent_end' && isset($event['messages'])) {
                // Find the last assistant message
                /** @var list<array<string, mixed>> $messages */
                $messages = array_reverse((array) $event['messages']);
                foreach ($messages as $message) {
                    if (($message['role'] ?? '') === 'assistant' && isset($message['content'])) {
                        $resultText = self::extractTextFromContent($message['content']);

                        break;
                    }
                }
            }

            // Fallback: ephemeral mode (--no-session) may not emit agent_end.
            // Extract from turn_end and message_end events that carry assistant content.
            if ($resultText === '' && ($type === 'turn_end' || $type === 'message_end')) {
                $message = $event['message'] ?? null;
                if (is_array($message) && ($message['role'] ?? '') === 'assistant' && isset($message['content'])) {
                    $extracted = self::extractTextFromContent($message['content']);
                    if ($extracted !== '') {
                        $resultText = $extracted;
                    }
                }
            }
        }

        if ($resultText === '' && $sessionId === '') {
            throw CliParseException::unexpectedFormat(
                'No session or agent_end events found in Pi output',
                $json,
            );
        }

        if ($firstTimestamp !== null && $lastTimestamp !== null) {
            $durationMs = max(0, ($lastTimestamp - $firstTimestamp) * 1000);
        }

        if (! $usageFound) {
            Log::warning('ConduitGateway: Pi CLI output did not include usage data; cost and token accounting remain external.', [
                'session_id' => $sessionId,
            ]);
        }

        $isError = $resultText === '' && $sessionId !== '' && $sessionId !== '0';

        return new self(
            sessionId: $sessionId,
            result: $resultText,
            costUsd: $costUsd,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            durationMs: $durationMs,
            numTurns: $numTurns,
            isError: $isError,
            model: (string) ($overrides['model'] ?? ''),
        );
    }

    /**
     * Find the result event from decoded JSON (Claude format).
     *
     * @return array<string, mixed>
     *
     * @throws CliParseException
     */
    private static function findResultEvent(mixed $decoded, string $rawJson): array
    {
        // Single object with type=result
        if (is_array($decoded) && isset($decoded['type']) && $decoded['type'] === 'result') {
            return $decoded;
        }

        // JSON array of events
        if (is_array($decoded) && array_is_list($decoded)) {
            /** @var list<array<string, mixed>> $events */
            $events = $decoded;

            foreach (array_reverse($events) as $event) {
                if (isset($event['type']) && $event['type'] === 'result') {
                    return $event;
                }
            }

            throw CliParseException::unexpectedFormat(
                'No "type": "result" event found in output array',
                $rawJson,
            );
        }

        throw CliParseException::unexpectedFormat(
            'Expected JSON array of events or single result object',
            $rawJson,
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private static function extractUsage(array $event): ?array
    {
        $message = $event['message'] ?? null;

        if (is_array($message) && is_array($message['usage'] ?? null)) {
            return $message['usage'];
        }

        return is_array($event['usage'] ?? null) ? $event['usage'] : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function resolveUsageKey(array $event): ?string
    {
        $message = is_array($event['message'] ?? null) ? $event['message'] : [];

        foreach ([$event['requestId'] ?? null, $event['responseId'] ?? null, $message['requestId'] ?? null, $message['responseId'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Extract text content from a message content blocks array.
     *
     * @param  mixed  $content  Array of content blocks
     */
    private static function extractTextFromContent(mixed $content): string
    {
        if (! is_array($content)) {
            return '';
        }

        $textParts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $textParts[] = (string) ($block['text'] ?? '');
            }
        }

        return implode("\n", $textParts);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array{0: int, 1: int, 2: float}
     */
    private static function accumulateUsage(array $usage, int $inputTokens, int $outputTokens, float $costUsd): array
    {
        $inputTokens += (int) ($usage['input_tokens'] ?? $usage['input'] ?? 0);
        $inputTokens += (int) ($usage['cache_creation_input_tokens'] ?? $usage['cache_write_input_tokens'] ?? $usage['cacheWrite'] ?? 0);
        $inputTokens += (int) ($usage['cache_read_input_tokens'] ?? $usage['cacheRead'] ?? 0);
        $outputTokens += (int) ($usage['output_tokens'] ?? $usage['output'] ?? 0);

        $cost = $usage['cost'] ?? null;
        if (is_array($cost)) {
            $total = $cost['total'] ?? null;
            if (is_numeric($total)) {
                $costUsd += (float) $total;
            }
        }

        return [$inputTokens, $outputTokens, $costUsd];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function extractTimestamp(array $event): ?int
    {
        $timestamp = $event['timestamp'] ?? $event['created_at'] ?? null;

        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        $parsed = strtotime($timestamp);

        return $parsed === false ? null : $parsed;
    }

    /**
     * Parse JSONL (newline-delimited JSON) into events array.
     *
     * @return list<array<string, mixed>>
     *
     * @throws CliParseException
     */
    private static function parseJsonLines(string $json): array
    {
        $lines = array_filter(
            explode("\n", trim($json)),
            fn (string $line): bool => $line !== '' && $line[0] === '{',
        );

        if ($lines === []) {
            throw CliParseException::invalidJson($json, 'No valid JSON lines found');
        }

        $events = [];
        $skipped = 0;
        foreach ($lines as $line) {
            /** @var array<string, mixed>|null $event */
            $event = json_decode($line, true);
            if ($event === null && json_last_error() !== JSON_ERROR_NONE) {
                $skipped++;

                continue; // Skip malformed lines (e.g., terminal escape sequences)
            }
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        if ($skipped > 0) {
            Log::warning('ConduitGateway: skipped malformed JSONL lines', ['count' => $skipped]);
        }

        return $events;
    }
}
