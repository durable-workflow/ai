<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Providers;

use DurableWorkflow\AI\SandboxToolResult;
use JsonException;
use RuntimeException;

/** @internal */
final class E2bConnectStreamParser
{
    private const FLAG_COMPRESSED = 0x01;

    private const FLAG_END_STREAM = 0x02;

    private const KNOWN_FLAGS = self::FLAG_COMPRESSED | self::FLAG_END_STREAM;

    public static function parse(string $body): SandboxToolResult
    {
        $stdout = '';
        $stderr = '';
        $exitCode = 0;
        $sawProcessEnd = false;

        foreach (self::messages($body) as $message) {
            $event = is_array($message['event'] ?? null) ? $message['event'] : $message;
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $end = [];

            if (array_key_exists('end', $event)) {
                if (! is_array($event['end'])) {
                    throw new RuntimeException('E2B Connect response contained an invalid process end event.');
                }

                $end = $event['end'];
                $sawProcessEnd = true;
            }

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

        if (! $sawProcessEnd) {
            throw new RuntimeException('E2B Connect response ended before the process result was received.');
        }

        return new SandboxToolResult($exitCode, $stdout, $stderr);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function messages(string $body): array
    {
        $messages = [];
        $offset = 0;
        $bodyLength = strlen($body);
        $sawEndStream = false;

        while ($offset < $bodyLength) {
            if ($bodyLength - $offset < 5) {
                throw new RuntimeException('E2B Connect response ended inside an envelope header.');
            }

            $flags = ord($body[$offset]);
            $lengthBytes = unpack('Nlength', substr($body, $offset + 1, 4));
            $payloadLength = is_array($lengthBytes) ? $lengthBytes['length'] : null;
            $offset += 5;

            if (($flags & ~self::KNOWN_FLAGS) !== 0) {
                throw new RuntimeException("E2B Connect response used unsupported envelope flags: {$flags}.");
            }

            if (($flags & self::FLAG_COMPRESSED) !== 0) {
                throw new RuntimeException('E2B Connect response unexpectedly used a compressed envelope.');
            }

            if (! is_int($payloadLength) || $payloadLength > $bodyLength - $offset) {
                throw new RuntimeException('E2B Connect response ended inside an envelope payload.');
            }

            $payload = substr($body, $offset, $payloadLength);
            $offset += $payloadLength;

            try {
                $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('E2B Connect response contained an invalid JSON envelope.', previous: $exception);
            }

            if (! is_array($decoded)) {
                throw new RuntimeException('E2B Connect response envelope must contain a JSON object.');
            }

            if (($flags & self::FLAG_END_STREAM) !== 0) {
                if ($sawEndStream || $offset !== $bodyLength) {
                    throw new RuntimeException('E2B Connect response end-stream envelope was not final.');
                }

                $sawEndStream = true;
                self::requireSuccessfulEndStream($decoded);

                continue;
            }

            if ($sawEndStream) {
                throw new RuntimeException('E2B Connect response contained data after its end-stream envelope.');
            }

            $messages[] = $decoded;
        }

        if (! $sawEndStream) {
            throw new RuntimeException('E2B Connect response did not include an end-stream envelope.');
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $endStream
     */
    private static function requireSuccessfulEndStream(array $endStream): void
    {
        if (! array_key_exists('error', $endStream)) {
            return;
        }

        $error = $endStream['error'];

        if (! is_array($error)) {
            throw new RuntimeException('E2B Connect response contained an invalid end-stream error.');
        }

        $code = $error['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new RuntimeException('E2B Connect response contained an invalid end-stream error code.');
        }

        $message = is_string($error['message'] ?? null) ? $error['message'] : 'no error message';

        throw new RuntimeException("E2B Connect stream failed with {$code}: {$message}");
    }

    private static function decodeBytes(string $value): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new RuntimeException('E2B Connect response contained invalid protobuf JSON bytes.');
        }

        return $decoded;
    }
}
