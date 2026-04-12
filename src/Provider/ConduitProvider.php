<?php

declare(strict_types=1);

namespace Conduit\Provider;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

class ConduitProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    /**
     * CLI providers have no API key — return empty credentials.
     *
     * @return array<string, mixed>
     */
    public function providerCredentials(): array
    {
        return [];
    }

    public function defaultTextModel(): string
    {
        return (string) ($this->config['model'] ?? $this->config['default_model'] ?? 'claude-sonnet-4-5');
    }

    public function cheapestTextModel(): string
    {
        return $this->defaultTextModel();
    }

    public function smartestTextModel(): string
    {
        return $this->defaultTextModel();
    }
}
