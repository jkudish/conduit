<?php

declare(strict_types=1);

namespace Conduit\Exceptions;

use Conduit\DTOs\CliProcessResult;

class CliCommandException extends ConduitException
{
    /**
     * @param  list<string>  $args
     */
    public static function fromResult(CliProcessResult $result, array $args): self
    {
        $binary = $args[0] ?? 'unknown';
        $errorPreview = mb_substr(trim($result->error), 0, 200);

        // Redact long arguments to prevent user prompt data leaking into exception reports.
        // For CLI drivers, the prompt is typically a long string passed after the binary + flag.
        $redactedArgs = array_map(
            fn (string $arg): string => mb_strlen($arg) > 100 ? mb_substr($arg, 0, 100).'…[redacted]' : $arg,
            $args,
        );

        // Truncate output to prevent full CLI payloads (which contain user prompts) from
        // leaking into error reporters such as Flare.
        $truncatedOutput = mb_strlen($result->output) > 500
            ? mb_substr($result->output, 0, 500).'…[truncated]'
            : $result->output;

        return new self(
            message: "CLI command '{$binary}' failed (exit {$result->exitCode}): {$errorPreview}",
            command: $redactedArgs,
            output: $truncatedOutput,
            errorOutput: mb_strlen($result->error) > 500
                ? mb_substr($result->error, 0, 500).'…[truncated]'
                : trim($result->error),
            code: $result->exitCode,
        );
    }
}
