<?php

declare(strict_types=1);

namespace Conduit\Responses;

use Laravel\Ai\Responses\Data\Meta;

class ConduitMeta extends Meta
{
    public function __construct(
        ?string $provider = null,
        ?string $model = null,
        public readonly ?string $sessionId = null,
        public readonly float $costUsd = 0.0,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly int $durationMs = 0,
        public readonly int $numTurns = 0,
        public readonly bool $isError = false,
    ) {
        parent::__construct($provider, $model);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'session_id' => $this->sessionId,
            'cost_usd' => $this->costUsd,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'duration_ms' => $this->durationMs,
            'num_turns' => $this->numTurns,
            'is_error' => $this->isError,
        ];
    }
}
