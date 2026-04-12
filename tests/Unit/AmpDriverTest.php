<?php

declare(strict_types=1);

use Conduit\Drivers\AmpDriver;
use Conduit\Exceptions\CliNotInstalledException;

it('builds the correct command for a basic prompt', function (): void {
    $driver = new AmpDriver(mode: 'smart');
    $command = $driver->buildCommand('Write hello world');

    expect($command)->toBe([
        'amp', '-x', 'Write hello world',
        '--stream-json', '--dangerously-allow-all',
        '--mode', 'smart',
    ]);
});

it('omits dangerously-allow-all when disabled', function (): void {
    $driver = new AmpDriver(mode: 'smart', allowAllTools: false);
    $command = $driver->buildCommand('Write hello world');

    expect($command)->not->toContain('--dangerously-allow-all');
    expect($command)->toContain('--stream-json');
    expect($command)->toContain('--mode');
});

it('overrides mode from model option', function (): void {
    $driver = new AmpDriver(mode: 'smart');
    $command = $driver->buildCommand('Test', ['model' => 'deep']);

    $modeIndex = array_search('--mode', $command, true);
    expect($command[$modeIndex + 1])->toBe('deep');
});

it('prepends system prompt to user prompt', function (): void {
    $driver = new AmpDriver;
    $command = $driver->buildCommand('Write tests', ['system_prompt' => 'You are a test engineer.']);

    // The prompt argument (index 2) should have system prompt prepended
    expect($command[2])->toBe("You are a test engineer.\n\nWrite tests");
});

it('includes mcp-config when provided', function (): void {
    $driver = new AmpDriver;
    $command = $driver->buildCommand('Test', ['mcp_config' => '/path/to/.mcp.json']);

    expect($command)->toContain('--mcp-config');
    $index = array_search('--mcp-config', $command, true);
    expect($command[$index + 1])->toBe('/path/to/.mcp.json');
});

it('ignores session_id option', function (): void {
    $driver = new AmpDriver;
    $command = $driver->buildCommand('Test', ['session_id' => 'should-be-ignored']);

    // Command should not contain any session-related flags
    expect(implode(' ', $command))->not->toContain('should-be-ignored');
});

it('detects installed binary via absolute path', function (): void {
    $driver = new AmpDriver(binary: PHP_BINARY);
    expect($driver->isInstalled())->toBeTrue();
});

it('detects missing binary via absolute path', function (): void {
    $driver = new AmpDriver(binary: '/nonexistent/binary');
    expect($driver->isInstalled())->toBeFalse();
});

it('returns correct driver name', function (): void {
    $driver = new AmpDriver;
    expect($driver->name())->toBe('amp');
});

it('throws CliNotInstalledException when binary is missing', function (): void {
    $driver = new AmpDriver(binary: '/nonexistent/binary');
    $driver->execute('Test');
})->throws(CliNotInstalledException::class);
