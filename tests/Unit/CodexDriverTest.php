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

it('uses instructions-file when system_prompt_file is pre-resolved', function (): void {
    // buildCommand() is side-effect-free — callers must resolve the temp file first (as execute() does).
    $driver = new CodexDriver;
    $tempFile = tempnam(sys_get_temp_dir(), 'conduit-test-');
    file_put_contents($tempFile, 'You are a helpful assistant.');

    try {
        $command = $driver->buildCommand('Test', ['system_prompt_file' => $tempFile]);

        $fileIndex = array_search('--instructions-file', $command, true);
        expect($fileIndex)->not->toBeFalse();
        expect($command[$fileIndex + 1])->toBe($tempFile);
    } finally {
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
});

it('does not create temp files when only system_prompt is passed to buildCommand', function (): void {
    // buildCommand() is side-effect-free: it ignores system_prompt without a pre-resolved file.
    // execute() is responsible for creating the temp file before calling buildCommand().
    $driver = new CodexDriver;
    $command = $driver->buildCommand('Test', ['system_prompt' => 'You are a helpful assistant.']);

    expect($command)->not->toContain('--instructions-file');
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
