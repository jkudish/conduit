<?php

declare(strict_types=1);

use Conduit\Drivers\PiDriver;
use Conduit\Exceptions\CliNotInstalledException;

it('builds the correct command for a basic prompt', function (): void {
    $driver = new PiDriver(model: 'claude-sonnet-4-5', provider: 'anthropic');
    $command = $driver->buildCommand('Write hello world');

    expect($command)->toBe([
        'pi', '-p', 'Write hello world',
        '--mode', 'json',
        '--model', 'claude-sonnet-4-5',
        '--provider', 'anthropic',
        '--no-session',
    ]);
});

it('overrides model from options', function (): void {
    $driver = new PiDriver(model: 'claude-sonnet-4-5');
    $command = $driver->buildCommand('Test', ['model' => 'claude-opus-4']);

    $modelIndex = array_search('--model', $command, true);
    expect($command[$modelIndex + 1])->toBe('claude-opus-4');
});

it('overrides provider from options', function (): void {
    $driver = new PiDriver(provider: 'anthropic');
    $command = $driver->buildCommand('Test', ['provider' => 'openai']);

    $providerIndex = array_search('--provider', $command, true);
    expect($command[$providerIndex + 1])->toBe('openai');
});

it('includes system prompt as inline text', function (): void {
    $driver = new PiDriver;
    $command = $driver->buildCommand('Test', ['system_prompt' => 'You are a helpful assistant.']);

    expect($command)->toContain('--system-prompt');
    $index = array_search('--system-prompt', $command, true);
    expect($command[$index + 1])->toBe('You are a helpful assistant.');
});

it('merges append_system_prompt into system prompt', function (): void {
    $driver = new PiDriver;
    $command = $driver->buildCommand('Test', [
        'system_prompt' => 'Base instructions.',
        'append_system_prompt' => 'Extra context.',
    ]);

    $index = array_search('--system-prompt', $command, true);
    expect($command[$index + 1])->toBe("Base instructions.\n\nExtra context.");
});

it('handles append_system_prompt without system_prompt', function (): void {
    $driver = new PiDriver;
    $command = $driver->buildCommand('Test', ['append_system_prompt' => 'Extra context.']);

    expect($command)->toContain('--system-prompt');
    $index = array_search('--system-prompt', $command, true);
    expect($command[$index + 1])->toBe('Extra context.');
});

it('includes tools when provided via options', function (): void {
    $driver = new PiDriver;
    $command = $driver->buildCommand('Test', ['allowed_tools' => ['read', 'bash', 'edit']]);

    expect($command)->toContain('--tools');
    $index = array_search('--tools', $command, true);
    expect($command[$index + 1])->toBe('read,bash,edit');
});

it('uses constructor tools as default', function (): void {
    $driver = new PiDriver(tools: ['read', 'write']);
    $command = $driver->buildCommand('Test');

    $index = array_search('--tools', $command, true);
    expect($command[$index + 1])->toBe('read,write');
});

it('includes thinking level when provided', function (): void {
    $driver = new PiDriver;
    $command = $driver->buildCommand('Test', ['thinking' => 'high']);

    expect($command)->toContain('--thinking');
    $index = array_search('--thinking', $command, true);
    expect($command[$index + 1])->toBe('high');
});

it('includes session flag for session id', function (): void {
    $driver = new PiDriver;
    $command = $driver->buildCommand('Test', ['session_id' => '/path/to/session.json']);

    expect($command)->toContain('--session');
    $index = array_search('--session', $command, true);
    expect($command[$index + 1])->toBe('/path/to/session.json');
    expect($command)->not->toContain('--no-session');
});

it('uses no-session when no session id provided', function (): void {
    $driver = new PiDriver;
    $command = $driver->buildCommand('Test');

    expect($command)->toContain('--no-session');
    expect($command)->not->toContain('--session');
});

it('detects installed binary via absolute path', function (): void {
    $driver = new PiDriver(binary: PHP_BINARY);
    expect($driver->isInstalled())->toBeTrue();
});

it('detects missing binary via absolute path', function (): void {
    $driver = new PiDriver(binary: '/nonexistent/binary');
    expect($driver->isInstalled())->toBeFalse();
});

it('returns correct driver name', function (): void {
    $driver = new PiDriver;
    expect($driver->name())->toBe('pi');
});

it('throws CliNotInstalledException when binary is missing', function (): void {
    $driver = new PiDriver(binary: '/nonexistent/binary');
    $driver->execute('Test');
})->throws(CliNotInstalledException::class);
