<?php

declare(strict_types=1);

namespace Conduit\Gateway;

use Conduit\ConduitContext;
use Conduit\Contracts\CliDriver;
use Conduit\Responses\ConduitMeta;
use Generator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;

class ConduitGateway implements Gateway
{
    public function __construct(
        protected CliDriver $driver,
    ) {}

    /**
     * Generate text by executing the CLI driver.
     *
     * Reads additional options (session_id, cli_tools, etc.) from ConduitContext.
     * The CLI handles its own tool execution -- SDK $tools parameter is ignored.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        if ($tools !== [] || $schema !== null) {
            Log::warning('ConduitGateway: AI SDK tools and structured output schemas are not supported by CLI providers — these parameters are ignored.');
        }

        // Build the user prompt from the last message
        $prompt = $this->extractPromptFromMessages($messages);

        // Merge ConduitContext with gateway parameters
        $driverOptions = ConduitContext::toDriverOptions();

        // Use instructions as system prompt if not already set via context
        if ($instructions !== null && $instructions !== '' && ! isset($driverOptions['system_prompt'])) {
            $driverOptions['system_prompt'] = $instructions;
        }

        // Override model from gateway parameter
        $driverOptions['model'] = $model;

        // Apply timeout
        if ($timeout !== null) {
            $driverOptions['timeout'] = $timeout;
        }

        $result = $this->driver->execute($prompt, $driverOptions);

        return new StepResponse(
            $result->result,
            [],
            FinishReason::Stop,
            new TextUsage(
                inputTokens: $result->inputTokens,
                outputTokens: $result->outputTokens,
            ),
            new ConduitMeta(
                provider: $this->driver->name().'-cli',
                model: $result->model !== '' ? $result->model : $model,
                sessionId: $result->sessionId,
                costUsd: $result->costUsd,
                promptTokens: $result->inputTokens,
                completionTokens: $result->outputTokens,
                durationMs: $result->durationMs,
                numTurns: $result->numTurns,
                isError: $result->isError,
            ),
        );
    }

    /**
     * Streaming is not supported for CLI providers (deferred to v2).
     *
     * @throws \RuntimeException
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        throw new \RuntimeException('Streaming is not supported for CLI providers. Use prompt() instead.');
    }

    /**
     * Audio generation is not supported for CLI providers.
     *
     * @throws \RuntimeException
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        throw new \RuntimeException('Audio generation is not supported for CLI providers.');
    }

    /**
     * Embedding generation is not supported for CLI providers.
     *
     * @param  string[]  $inputs
     *
     * @throws \RuntimeException
     */
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        throw new \RuntimeException('Embedding generation is not supported for CLI providers.');
    }

    /**
     * Image generation is not supported for CLI providers.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
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
        array $providerOptions = [],
    ): ImageResponse {
        throw new \RuntimeException('Image generation is not supported for CLI providers.');
    }

    /**
     * Transcription is not supported for CLI providers.
     *
     * @throws \RuntimeException
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        throw new \RuntimeException('Transcription is not supported for CLI providers.');
    }

    /**
     * Extract the user prompt text from the messages array.
     *
     * Takes the last user message content as the prompt to send to the CLI.
     *
     * @param  array<int, mixed>  $messages
     */
    protected function extractPromptFromMessages(array $messages): string
    {
        // Walk backwards to find the last user message
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof UserMessage) {
                return (string) $message->content;
            }
        }

        // Fallback: if messages array contains strings
        if ($messages !== []) {
            $lastMessage = end($messages);
            if (is_string($lastMessage)) {
                return $lastMessage;
            }

            // Messages present but no UserMessage found — log to surface mismatches early
            Log::warning('ConduitGateway: non-empty messages array yielded no user prompt', [
                'messageCount' => count($messages),
                'messageTypes' => array_map(fn (mixed $m): string => is_object($m) ? $m::class : gettype($m), $messages),
            ]);
        }

        return '';
    }
}
