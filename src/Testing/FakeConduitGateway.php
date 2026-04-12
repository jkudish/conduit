<?php

declare(strict_types=1);

namespace Conduit\Testing;

use Closure;
use Conduit\Responses\ConduitMeta;
use Generator;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use PHPUnit\Framework\Assert;

class FakeConduitGateway implements Gateway
{
    /** @var list<TextResponse> */
    protected array $responseQueue = [];

    /** @var list<array{model: string, instructions: ?string, messages: array<int, mixed>, options: ?TextGenerationOptions}> */
    protected array $recorded = [];

    protected int $callCount = 0;

    /**
     * Queue a response to be returned by the next generateText() call.
     */
    public function queueResponse(TextResponse $response): self
    {
        $this->responseQueue[] = $response;

        return $this;
    }

    /**
     * Queue a simple text response.
     */
    public function queueText(string $text, ?string $sessionId = null): self
    {
        return $this->queueResponse(new TextResponse(
            $text,
            new Usage,
            new ConduitMeta(
                provider: 'fake-cli',
                model: 'fake-model',
                sessionId: $sessionId ?? 'fake-session-'.($this->callCount + count($this->responseQueue) + 1),
            ),
        ));
    }

    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $this->callCount++;
        $this->recorded[] = [
            'model' => $model,
            'instructions' => $instructions,
            'messages' => $messages,
            'options' => $options,
        ];

        if ($this->responseQueue !== []) {
            return array_shift($this->responseQueue);
        }

        return new TextResponse(
            'Fake CLI response #'.$this->callCount,
            new Usage,
            new ConduitMeta(
                provider: 'fake-cli',
                model: $model,
                sessionId: 'fake-session-'.$this->callCount,
            ),
        );
    }

    /**
     * @throws \RuntimeException
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        throw new \RuntimeException('Streaming is not supported in the fake gateway.');
    }

    /**
     * @throws \RuntimeException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
    ): AudioResponse {
        throw new \RuntimeException('Audio generation is not supported for CLI providers.');
    }

    /**
     * @param  string[]  $inputs
     *
     * @throws \RuntimeException
     */
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30): EmbeddingsResponse
    {
        throw new \RuntimeException('Embedding generation is not supported for CLI providers.');
    }

    /**
     * @param  array<ImageFile>  $attachments
     *
     * @throws \RuntimeException
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        throw new \RuntimeException('Image generation is not supported for CLI providers.');
    }

    /**
     * @throws \RuntimeException
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
    ): TranscriptionResponse {
        throw new \RuntimeException('Transcription is not supported for CLI providers.');
    }

    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        return $this;
    }

    /**
     * Assert generateText() was called a specific number of times, or at least once.
     */
    public function assertCalled(?int $times = null): self
    {
        if ($times !== null) {
            Assert::assertCount(
                $times,
                $this->recorded,
                "Expected generateText() to be called [{$times}] times, but was called [".count($this->recorded).'] times.',
            );
        } else {
            Assert::assertNotEmpty(
                $this->recorded,
                'Expected generateText() to be called at least once.',
            );
        }

        return $this;
    }

    /**
     * Assert generateText() was never called.
     */
    public function assertNotCalled(): self
    {
        return $this->assertCalled(0);
    }

    /**
     * Assert generateText() was called with instructions containing the given string.
     */
    public function assertInstructionsContain(string $substring): self
    {
        $found = collect($this->recorded)->filter(
            fn (array $record): bool => $record['instructions'] !== null
                && str_contains($record['instructions'], $substring),
        );

        Assert::assertTrue(
            $found->isNotEmpty(),
            "Expected generateText() to be called with instructions containing [{$substring}].",
        );

        return $this;
    }

    /**
     * Assert generateText() was called with a specific model.
     */
    public function assertModelUsed(string $model): self
    {
        $found = collect($this->recorded)->filter(
            fn (array $record): bool => $record['model'] === $model,
        );

        Assert::assertTrue(
            $found->isNotEmpty(),
            "Expected generateText() to be called with model [{$model}].",
        );

        return $this;
    }

    /**
     * Get all recorded generateText() calls.
     *
     * @return list<array{model: string, instructions: ?string, messages: array<int, mixed>, options: ?TextGenerationOptions}>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }
}
