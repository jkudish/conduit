<?php

declare(strict_types=1);

use Conduit\Drivers\ClaudeDriver;
use Conduit\Exceptions\CliNotInstalledException;

it('builds the correct command for a basic prompt', function (): void {
    $driver = new ClaudeDriver(model: 'claude-sonnet-4-5');
    $command = $driver->buildCommand('Write hello world');

    expect($command)->toBe([
        'claude', '-p', 'Write hello world',
        '--output-format', 'json',
        '--model', 'claude-sonnet-4-5',
        '--max-turns', '10',
    ]);
});

it('overrides model from options', function (): void {
    $driver = new ClaudeDriver(model: 'claude-sonnet-4-5');
    $command = $driver->buildCommand('Test', ['model' => 'claude-opus-4']);

    $modelIndex = array_search('--model', $command, true);
    expect($command[$modelIndex + 1])->toBe('claude-opus-4');
});

it('overrides max turns from options', function (): void {
    $driver = new ClaudeDriver(maxTurns: 10);
    $command = $driver->buildCommand('Test', ['max_turns' => 25]);

    $index = array_search('--max-turns', $command, true);
    expect($command[$index + 1])->toBe('25');
});

it('uses system-prompt-file when pre-resolved path is provided', function (): void {
    $driver = new ClaudeDriver;
    $tempFile = tempnam(sys_get_temp_dir(), 'conduit-test-');
    file_put_contents($tempFile, 'You are a helpful assistant.');

    try {
        $command = $driver->buildCommand('Test', ['system_prompt_file' => $tempFile]);

        $fileIndex = array_search('--system-prompt-file', $command, true);
        expect($fileIndex)->not->toBeFalse();
        expect($command[$fileIndex + 1])->toBe($tempFile);
    } finally {
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
});

it('ignores system_prompt without pre-resolved file in buildCommand', function (): void {
    $driver = new ClaudeDriver;
    $command = $driver->buildCommand('Test', ['system_prompt' => 'You are a helpful assistant.']);

    expect($command)->not->toContain('--system-prompt-file');
});

it('includes append-system-prompt when provided', function (): void {
    $driver = new ClaudeDriver;
    $command = $driver->buildCommand('Test', ['append_system_prompt' => 'Extra context']);

    expect($command)->toContain('--append-system-prompt');
    $index = array_search('--append-system-prompt', $command, true);
    expect($command[$index + 1])->toBe('Extra context');
});

it('includes allowed tools when provided', function (): void {
    $driver = new ClaudeDriver;
    $command = $driver->buildCommand('Test', ['allowed_tools' => ['Read', 'Edit', 'Bash']]);

    expect($command)->toContain('--allowedTools');
    $index = array_search('--allowedTools', $command, true);
    expect($command[$index + 1])->toBe('Read,Edit,Bash');
});

it('uses constructor allowed tools as default', function (): void {
    $driver = new ClaudeDriver(allowedTools: ['Read', 'Write']);
    $command = $driver->buildCommand('Test');

    $index = array_search('--allowedTools', $command, true);
    expect($command[$index + 1])->toBe('Read,Write');
});

it('includes mcp-config when provided', function (): void {
    $driver = new ClaudeDriver;
    $command = $driver->buildCommand('Test', ['mcp_config' => '/path/to/.mcp.json']);

    expect($command)->toContain('--mcp-config');
    $index = array_search('--mcp-config', $command, true);
    expect($command[$index + 1])->toBe('/path/to/.mcp.json');
});

it('includes resume flag for session id', function (): void {
    $driver = new ClaudeDriver;
    $command = $driver->buildCommand('Test', ['session_id' => 'abc-123']);

    expect($command)->toContain('--resume');
    $index = array_search('--resume', $command, true);
    expect($command[$index + 1])->toBe('abc-123');
});

it('detects installed binary via absolute path', function (): void {
    $driver = new ClaudeDriver(binary: PHP_BINARY);
    expect($driver->isInstalled())->toBeTrue();
});

it('detects missing binary via absolute path', function (): void {
    $driver = new ClaudeDriver(binary: '/nonexistent/binary');
    expect($driver->isInstalled())->toBeFalse();
});

it('returns correct driver name', function (): void {
    $driver = new ClaudeDriver;
    expect($driver->name())->toBe('claude');
});

it('throws CliNotInstalledException when binary is missing', function (): void {
    $driver = new ClaudeDriver(binary: '/nonexistent/binary');
    $driver->execute('Test');
})->throws(CliNotInstalledException::class);
