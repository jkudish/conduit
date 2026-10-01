<?php

declare(strict_types=1);

use Conduit\ConduitContext;
use Conduit\Contracts\CliDriver;
use Conduit\Drivers\AmpDriver;
use Conduit\Drivers\ClaudeDriver;
use Conduit\Drivers\CodexDriver;
use Conduit\Drivers\PiDriver;
use Conduit\DTOs\CliRunResult;
use Conduit\Gateway\ConduitGateway;
use Conduit\Responses\ConduitMeta;
use Conduit\Testing\FakeConduitGateway;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;

use function Laravel\Ai\agent;

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

    $provider->shouldReceive('name')->andReturn('fake-cli');
    $response = (new TextGenerationLoop($gateway))->generate(
        provider: $provider,
        model: 'claude-sonnet-4-5',
        instructions: 'Be helpful',
        messages: [new UserMessage('What is 2+2?')],
    );

    expect($response)->toBeInstanceOf(TextResponse::class)
        ->and($response->text)->toBe('Hello!')
        ->and($response->usage->inputTokens)->toBe(100)
        ->and($response->usage->outputTokens)->toBe(50)
        ->and($response->usage->toArray())->toMatchArray(['input_tokens' => 100, 'output_tokens' => 50])
        ->and($response->steps)->toHaveCount(1)
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

    $provider->shouldReceive('name')->andReturn('fake-cli');
    (new TextGenerationLoop($gateway))->generate(
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

    $provider->shouldReceive('name')->andReturn('fake-cli');
    (new TextGenerationLoop($gateway))->generate(
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

    $provider->shouldReceive('name')->andReturn('fake-cli');
    (new TextGenerationLoop($gateway))->generate(
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

it('throws RuntimeException on generateStreamStep', function (): void {
    $driver = createFakeDriver();
    $gateway = new ConduitGateway($driver);
    $provider = Mockery::mock(TextProvider::class);

    $generator = $gateway->generateStreamStep('inv-1', $provider, 'test', null, [], [], null, null, null, new StepContext);
    $generator->current(); // Force generator execution
})->throws(RuntimeException::class, 'Streaming is not supported');

it('routes SDK agent prompts through each registered CLI provider', function (string $name, string $driverClass): void {
    config()->set("ai.providers.{$name}", ['driver' => $name]);
    ConduitContext::set(sessionId: 'resume-me', systemPrompt: 'Context instructions');

    $driver = Mockery::mock($driverClass);
    $driver->shouldReceive('name')->andReturn(str_replace('-cli', '', $name));
    $driver->shouldReceive('execute')->once()->withArgs(function (string $prompt, array $options): bool {
        return $prompt === 'Refactor safely'
            && $options['system_prompt'] === 'Context instructions'
            && $options['session_id'] === 'resume-me'
            && $options['model'] === 'requested-model'
            && $options['timeout'] === 47;
    })->andReturn(new CliRunResult('returned-session', 'Done', 0.12, 37, 11, 123, 2, false, 'actual-model'));
    $this->app->instance($driverClass, $driver);

    $response = agent('Agent instructions')->prompt('Refactor safely', provider: $name, model: 'requested-model', timeout: 47);

    expect($response->text)->toBe('Done')
        ->and($response->usage->inputTokens)->toBe(37)
        ->and($response->usage->outputTokens)->toBe(11)
        ->and($response->steps)->toHaveCount(1)
        ->and($response->meta)->toBeInstanceOf(ConduitMeta::class)
        ->and($response->meta->sessionId)->toBe('returned-session')
        ->and($response->meta->model)->toBe('actual-model')
        ->and($response->meta->costUsd)->toBe(0.12);
})->with([
    ['claude-cli', ClaudeDriver::class],
    ['codex-cli', CodexDriver::class],
    ['amp-cli', AmpDriver::class],
    ['pi-cli', PiDriver::class],
]);

it('runs queued and default fake responses through the SDK loop', function (): void {
    config()->set('ai.providers.claude-cli', ['driver' => 'claude-cli']);
    $fake = new FakeConduitGateway;
    $fake->assertNotCalled();
    $fake->queueResponse(new TextResponse('First', new TextUsage(37, 11), new ConduitMeta(sessionId: 'queued-session')));
    $fake->queueText('Second');
    app(AiManager::class)->textProvider('claude-cli')->useTextGateway($fake);

    $first = agent('Be helpful')->prompt('One', provider: 'claude-cli', model: 'test-model');
    $second = agent()->prompt('Two', provider: 'claude-cli', model: 'test-model');
    $third = agent()->prompt('Three', provider: 'claude-cli', model: 'test-model');

    expect($first->text)->toBe('First')
        ->and($first->usage->inputTokens)->toBe(37)
        ->and($first->usage->outputTokens)->toBe(11)
        ->and($first->meta->sessionId)->toBe('queued-session')
        ->and($second->text)->toBe('Second')
        ->and($second->meta->sessionId)->toBe('fake-session-2')
        ->and($third->text)->toBe('Fake CLI response #3')
        ->and($third->usage->totalTokens())->toBe(0)
        ->and($fake->getRecorded()[2]['messages'][0]->content)->toBe('Three');
    $fake->assertCalled(3)->assertModelUsed('test-model')->assertInstructionsContain('Be helpful');
});

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
