<?php

declare(strict_types=1);

use Conduit\ConduitContext;
use Conduit\Contracts\CliDriver;
use Conduit\DTOs\CliRunResult;
use Conduit\Gateway\ConduitGateway;
use Conduit\Responses\ConduitMeta;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TextResponse;

beforeEach(function (): void {
    ConduitContext::flush();
});

it('generates text by executing the CLI driver', function (): void {
    $driver = createFakeDriver(new CliRunResult(
        sessionId: 'sess-1',
        result: 'Hello!',
        costUsd: 0.01,
        inputTokens: 100,
        outputTokens: 50,
        durationMs: 1000,
        numTurns: 1,
        isError: false,
        model: 'claude-sonnet-4-5',
    ));

    $gateway = new ConduitGateway($driver);

    $provider = Mockery::mock(TextProvider::class);

    $response = $gateway->generateText(
        provider: $provider,
        model: 'claude-sonnet-4-5',
        instructions: 'Be helpful',
        messages: [new UserMessage('What is 2+2?')],
    );

    expect($response)->toBeInstanceOf(TextResponse::class)
        ->and($response->text)->toBe('Hello!')
        ->and($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->meta)->toBeInstanceOf(ConduitMeta::class)
        ->and($response->meta->sessionId)->toBe('sess-1')
        ->and($response->meta->costUsd)->toBe(0.01)
        ->and($response->meta->promptTokens)->toBe(100)
        ->and($response->meta->completionTokens)->toBe(50)
        ->and($response->meta->durationMs)->toBe(1000)
        ->and($response->meta->numTurns)->toBe(1);
});

it('passes instructions as system prompt to the driver', function (): void {
    $capturedOptions = null;
    $driver = createFakeDriver(callback: function (string $prompt, array $options) use (&$capturedOptions) {
        $capturedOptions = $options;
    });

    $gateway = new ConduitGateway($driver);
    $provider = Mockery::mock(TextProvider::class);

    $gateway->generateText(
        provider: $provider,
        model: 'test',
        instructions: 'You are a helpful assistant.',
        messages: [new UserMessage('Hello')],
    );

    expect($capturedOptions['system_prompt'])->toBe('You are a helpful assistant.');
});

it('reads session_id and cli_tools from ConduitContext', function (): void {
    ConduitContext::set(
        sessionId: 'resume-session-abc',
        cliTools: ['Read', 'Edit', 'Bash'],
    );

    $capturedOptions = null;
    $driver = createFakeDriver(callback: function (string $prompt, array $options) use (&$capturedOptions) {
        $capturedOptions = $options;
    });

    $gateway = new ConduitGateway($driver);
    $provider = Mockery::mock(TextProvider::class);

    $gateway->generateText(
        provider: $provider,
        model: 'test',
        instructions: null,
        messages: [new UserMessage('Continue')],
    );

    expect($capturedOptions['session_id'])->toBe('resume-session-abc')
        ->and($capturedOptions['allowed_tools'])->toBe(['Read', 'Edit', 'Bash']);
});

it('extracts prompt from the last user message', function (): void {
    $capturedPrompt = null;
    $driver = createFakeDriver(callback: function (string $prompt) use (&$capturedPrompt) {
        $capturedPrompt = $prompt;
    });

    $gateway = new ConduitGateway($driver);
    $provider = Mockery::mock(TextProvider::class);

    $gateway->generateText(
        provider: $provider,
        model: 'test',
        instructions: null,
        messages: [
            new UserMessage('First message'),
            new UserMessage('Second message'),
        ],
    );

    expect($capturedPrompt)->toBe('Second message');
});

it('throws RuntimeException on streamText', function (): void {
    $driver = createFakeDriver();
    $gateway = new ConduitGateway($driver);
    $provider = Mockery::mock(TextProvider::class);

    $generator = $gateway->streamText('inv-1', $provider, 'test', null);
    $generator->current(); // Force generator execution
})->throws(RuntimeException::class, 'Streaming is not supported');

// Helper to create a fake driver that returns a configurable result
function createFakeDriver(?CliRunResult $result = null, ?Closure $callback = null): CliDriver
{
    $defaultResult = $result ?? new CliRunResult(
        sessionId: 'fake-session',
        result: 'Fake response',
        costUsd: 0.0,
        inputTokens: 0,
        outputTokens: 0,
        durationMs: 0,
        numTurns: 1,
        isError: false,
        model: 'fake-model',
    );

    $mock = Mockery::mock(CliDriver::class);
    $mock->shouldReceive('name')->andReturn('fake');
    $mock->shouldReceive('execute')->andReturnUsing(
        function (string $prompt, array $options = []) use ($defaultResult, $callback) {
            if ($callback !== null) {
                $callback($prompt, $options);
            }

            return $defaultResult;
        },
    );

    return $mock;
}
