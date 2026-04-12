<?php

declare(strict_types=1);

use Conduit\Drivers\CodexDriver;
use Conduit\Exceptions\CliNotInstalledException;

it('builds the correct command for a basic prompt', function (): void {
    $driver = new CodexDriver(model: 'gpt-5.3-codex');
    $command = $driver->buildCommand('Write hello world');

    expect($command)->toBe([
        'codex', 'exec', 'Write hello world',
        '--json', '--full-auto',
        '-m', 'gpt-5.3-codex',
    ]);
});

it('overrides model from options', function (): void {
    $driver = new CodexDriver(model: 'gpt-5.3-codex');
    $command = $driver->buildCommand('Test', ['model' => 'o3']);

    $modelIndex = array_search('-m', $command, true);
    expect($command[$modelIndex + 1])->toBe('o3');
});

it('prepends system prompt to user prompt', function (): void {
    $driver = new CodexDriver;
    $command = $driver->buildCommand('Write tests', ['system_prompt' => 'You are a test engineer.']);

    // codex exec <prompt> --json ...
    expect($command[2])->toBe("You are a test engineer.\n\nWrite tests");
});

it('merges append_system_prompt with system prompt', function (): void {
    $driver = new CodexDriver;
    $command = $driver->buildCommand('Do work', [
        'system_prompt' => 'Base instructions.',
        'append_system_prompt' => 'Extra context.',
    ]);

    expect($command[2])->toBe("Base instructions.\n\nExtra context.\n\nDo work");
});

it('handles append_system_prompt without system_prompt', function (): void {
    $driver = new CodexDriver;
    $command = $driver->buildCommand('Do work', ['append_system_prompt' => 'Extra context.']);

    expect($command[2])->toBe("Extra context.\n\nDo work");
});

it('includes working directory when provided', function (): void {
    $driver = new CodexDriver;
    $command = $driver->buildCommand('Test', ['working_directory' => '/tmp/project']);

    expect($command)->toContain('-C');
    $index = array_search('-C', $command, true);
    expect($command[$index + 1])->toBe('/tmp/project');
});

it('uses resume subcommand for session id', function (): void {
    $driver = new CodexDriver;
    $command = $driver->buildCommand('Continue work', ['session_id' => 'thread-abc-123']);

    expect($command[0])->toBe('codex');
    expect($command[1])->toBe('exec');
    expect($command[2])->toBe('resume');
    expect($command[3])->toBe('thread-abc-123');
    expect($command[4])->toBe('Continue work');
});

it('detects installed binary via absolute path', function (): void {
    $driver = new CodexDriver(binary: PHP_BINARY);
    expect($driver->isInstalled())->toBeTrue();
});

it('detects missing binary via absolute path', function (): void {
    $driver = new CodexDriver(binary: '/nonexistent/binary');
    expect($driver->isInstalled())->toBeFalse();
});

it('returns correct driver name', function (): void {
    $driver = new CodexDriver;
    expect($driver->name())->toBe('codex');
});

it('throws CliNotInstalledException when binary is missing', function (): void {
    $driver = new CodexDriver(binary: '/nonexistent/binary');
    $driver->execute('Test');
})->throws(CliNotInstalledException::class);
