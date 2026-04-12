<?php

declare(strict_types=1);

namespace Conduit;

use Conduit\Drivers\AmpDriver;
use Conduit\Drivers\ClaudeDriver;
use Conduit\Drivers\CodexDriver;
use Conduit\Drivers\PiDriver;
use Conduit\Gateway\ConduitGateway;
use Conduit\Provider\ConduitProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;

class ConduitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/conduit.php', 'conduit');

        $this->registerDriverSingletons();
        $this->registerAiProviders();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/conduit.php' => config_path('conduit.php'),
            ], 'conduit-config');
        }

        AboutCommand::add('Conduit', fn (): array => array_filter([
            'Claude Binary' => (string) config('conduit.drivers.claude.binary'),
            'Claude Model' => (string) config('conduit.drivers.claude.model'),
            'Codex Binary' => (string) config('conduit.drivers.codex.binary'),
            'Amp Binary' => (string) config('conduit.drivers.amp.binary'),
            'Pi Binary' => (string) config('conduit.drivers.pi.binary'),
        ]));
    }

    protected function registerDriverSingletons(): void
    {
        $this->app->singleton(ClaudeDriver::class, function (): ClaudeDriver {
            /** @var array<string, mixed> $config */
            $config = config('conduit.drivers.claude', []);

            return new ClaudeDriver(
                binary: (string) ($config['binary'] ?? 'claude'),
                model: (string) ($config['model'] ?? 'claude-sonnet-4-5'),
                maxTurns: (int) ($config['max_turns'] ?? 10),
                timeout: (int) ($config['timeout'] ?? 600),
                allowedTools: array_values(array_map(strval(...), (array) ($config['allowed_tools'] ?? []))),
            );
        });

        $this->app->singleton(CodexDriver::class, function (): CodexDriver {
            /** @var array<string, mixed> $config */
            $config = config('conduit.drivers.codex', []);

            return new CodexDriver(
                binary: (string) ($config['binary'] ?? 'codex'),
                model: (string) ($config['model'] ?? 'gpt-5.3-codex'),
                timeout: (int) ($config['timeout'] ?? 300),
            );
        });

        $this->app->singleton(AmpDriver::class, function (): AmpDriver {
            /** @var array<string, mixed> $config */
            $config = config('conduit.drivers.amp', []);

            return new AmpDriver(
                binary: (string) ($config['binary'] ?? 'amp'),
                mode: (string) ($config['mode'] ?? 'smart'),
                timeout: (int) ($config['timeout'] ?? 300),
                allowAllTools: (bool) ($config['allow_all_tools'] ?? true),
            );
        });

        $this->app->singleton(PiDriver::class, function (): PiDriver {
            /** @var array<string, mixed> $config */
            $config = config('conduit.drivers.pi', []);

            return new PiDriver(
                binary: (string) ($config['binary'] ?? 'pi'),
                model: (string) ($config['model'] ?? 'claude-sonnet-4-5'),
                provider: (string) ($config['provider'] ?? 'anthropic'),
                timeout: (int) ($config['timeout'] ?? 600),
                tools: array_values(array_map(strval(...), (array) ($config['tools'] ?? []))),
            );
        });
    }

    protected function registerAiProviders(): void
    {
        $this->app->resolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('claude-cli', function ($app, $config) {
                /** @var array<string, mixed> $driverConfig */
                $driverConfig = config('conduit.drivers.claude', []);
                $mergedConfig = array_merge($driverConfig, $config);

                return new ConduitProvider(
                    new ConduitGateway($app->make(ClaudeDriver::class)),
                    $mergedConfig,
                    $app->make(Dispatcher::class),
                );
            });

            $manager->extend('codex-cli', function ($app, $config) {
                /** @var array<string, mixed> $driverConfig */
                $driverConfig = config('conduit.drivers.codex', []);
                $mergedConfig = array_merge($driverConfig, $config);

                return new ConduitProvider(
                    new ConduitGateway($app->make(CodexDriver::class)),
                    $mergedConfig,
                    $app->make(Dispatcher::class),
                );
            });

            $manager->extend('amp-cli', function ($app, $config) {
                /** @var array<string, mixed> $driverConfig */
                $driverConfig = config('conduit.drivers.amp', []);
                $mergedConfig = array_merge($driverConfig, $config);

                return new ConduitProvider(
                    new ConduitGateway($app->make(AmpDriver::class)),
                    $mergedConfig,
                    $app->make(Dispatcher::class),
                );
            });

            $manager->extend('pi-cli', function ($app, $config) {
                /** @var array<string, mixed> $driverConfig */
                $driverConfig = config('conduit.drivers.pi', []);
                $mergedConfig = array_merge($driverConfig, $config);

                return new ConduitProvider(
                    new ConduitGateway($app->make(PiDriver::class)),
                    $mergedConfig,
                    $app->make(Dispatcher::class),
                );
            });
        });
    }
}
