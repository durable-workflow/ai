<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Providers;

use DurableWorkflow\AI\SandboxToolResult;
use JsonException;

/** @internal */
final class E2bConnectStreamParser
{
    public static function parse(string $body): SandboxToolResult
    {
        $stdout = '';
        $stderr = '';
        $exitCode = 0;

        foreach (self::messages($body) as $message) {
            $event = is_array($message['event'] ?? null) ? $message['event'] : $message;
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $end = is_array($event['end'] ?? null) ? $event['end'] : [];

            foreach (['stdout', 'pty'] as $field) {
                if (is_string($data[$field] ?? null)) {
                    $stdout .= self::decodeBytes($data[$field]);
                }
            }

            if (is_string($data['stderr'] ?? null)) {
                $stderr .= self::decodeBytes($data['stderr']);
            }

            foreach ([$end['exitCode'] ?? null, $end['exit_code'] ?? null, $data['exitCode'] ?? null] as $candidate) {
                if (is_int($candidate) || (is_string($candidate) && is_numeric($candidate))) {
                    $exitCode = (int) $candidate;
                }
            }

            if (is_string($end['error'] ?? null) && $end['error'] !== '') {
                $stderr .= $end['error'];
                $exitCode = $exitCode === 0 ? 1 : $exitCode;
            }
        }

        return new SandboxToolResult($exitCode, $stdout, $stderr);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function messages(string $body): array
    {
        $messages = [];

        foreach (preg_split('/\R+/', trim($body)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    private static function decodeBytes(string $value): string
    {
        $decoded = base64_decode($value, true);

        return $decoded === false ? $value : $decoded;
    }
}
