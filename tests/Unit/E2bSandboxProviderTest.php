<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Unit;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Exceptions\PermanentSandboxProvisionException;
use DurableWorkflow\AI\Exceptions\SandboxGoneException;
use DurableWorkflow\AI\Exceptions\SandboxProvisionException;
use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use DurableWorkflow\AI\Providers\E2bSandboxProvider;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxToolCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class E2bSandboxProviderTest extends TestCase
{
    private Factory $http;

    private E2bSandboxProvider $provider;

    protected function setUp(): void
    {
        $this->http = new Factory;
        $this->provider = new E2bSandboxProvider(
            apiKey: 'e2b-test-key',
            templateId: 'coding-agent',
            apiBaseUrl: 'https://api.e2b.app',
            sandboxBaseUrl: 'https://sandbox.e2b.app',
            timeoutSeconds: 60,
            http: $this->http,
        );
    }

    public function test_provision_uses_documented_management_contract_and_bounded_ttl(): void
    {
        $this->http->fake([
            'https://api.e2b.app/sandboxes' => $this->http->response([
                'templateID' => 'coding-agent',
                'sandboxID' => 'sbx-123',
                'envdAccessToken' => 'not-retained-in-handle',
            ], 201),
        ]);

        $handle = $this->provider->provision(['lease_ttl_seconds' => 120]);

        $this->assertSame('sbx-123', $handle->id);
        $this->assertArrayNotHasKey('envd_access_token', $handle->metadata);
        $this->http->assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.e2b.app/sandboxes'
                && $request->hasHeader('X-API-Key', 'e2b-test-key')
                && $request['templateID'] === 'coding-agent'
                && $request['timeout'] === 120
                && $request['secure'] === true;
        });
    }

    public function test_provision_keeps_rate_limits_and_server_failures_retryable(): void
    {
        $statuses = [429, 500, 503];
        $this->http->fake(function () use (&$statuses) {
            return $this->http->response(
                ['message' => 'temporarily unavailable'],
                array_shift($statuses),
            );
        });

        foreach ([429, 500, 503] as $status) {
            try {
                $this->provider->provision();
                $this->fail("Expected HTTP {$status} to fail provisioning.");
            } catch (SandboxProvisionException $exception) {
                $this->assertFalse($exception instanceof PermanentSandboxProvisionException);
                $this->assertStringContainsString("HTTP {$status}", $exception->getMessage());
            }
        }
    }

    public function test_provision_classifies_missing_credentials_and_invalid_requests_as_permanent(): void
    {
        $providerWithoutCredentials = new E2bSandboxProvider(
            apiKey: '',
            templateId: 'coding-agent',
            apiBaseUrl: 'https://api.e2b.app',
            sandboxBaseUrl: 'https://sandbox.e2b.app',
            timeoutSeconds: 60,
            http: $this->http,
        );

        try {
            $providerWithoutCredentials->provision();
            $this->fail('Expected missing credentials to fail provisioning.');
        } catch (SandboxProvisionException $exception) {
            $this->assertSame(PermanentSandboxProvisionException::class, $exception::class);
        }

        $this->http->fake([
            'https://api.e2b.app/sandboxes' => $this->http->response(
                ['message' => 'templateID is invalid'],
                422,
            ),
        ]);

        try {
            $this->provider->provision();
            $this->fail('Expected an invalid request to fail provisioning.');
        } catch (SandboxProvisionException $exception) {
            $this->assertSame(PermanentSandboxProvisionException::class, $exception::class);
            $this->assertStringContainsString('HTTP 422', $exception->getMessage());
        }
    }

    public function test_lifecycle_uses_snapshot_template_timeout_and_idempotent_delete_routes(): void
    {
        $this->http->fake(function (Request $request) {
            return match (true) {
                $request->method() === 'POST' && str_ends_with($request->url(), '/snapshots') => $this->http->response(['snapshotID' => 'snapshot-template:v1'], 201),
                $request->method() === 'POST' && $request->url() === 'https://api.e2b.app/sandboxes' => $this->http->response([
                    'templateID' => 'snapshot-template:v1',
                    'sandboxID' => 'sbx-restored',
                ], 201),
                $request->method() === 'DELETE' && str_contains($request->url(), '/templates/') => $this->http->response([], 404),
                $request->method() === 'DELETE' => $this->http->response([], 404),
                default => $this->http->response([], 204),
            };
        });

        $handle = new SandboxHandle('sbx-123', 'e2b');
        $this->provider->renewLease($handle, 600);
        $snapshot = $this->provider->snapshot($handle);
        $restored = $this->provider->restore($snapshot);
        $this->provider->deleteSnapshot($snapshot);
        $this->provider->deleteSnapshot($snapshot);
        $this->provider->destroy($handle);

        $this->assertSame('sbx-restored', $restored->id);
        $this->assertTrue($this->provider->capabilities()->supports(SandboxCapability::SnapshotDeletion));
        $this->http->assertSentCount(6);
        $this->http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.e2b.app/sandboxes/sbx-123/timeout' && $request['timeout'] === 600);
        $this->http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.e2b.app/sandboxes' && $request['templateID'] === 'snapshot-template:v1');
        $this->http->assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.e2b.app/templates/snapshot-template%3Av1');
    }

    public function test_suspend_and_resume_fail_before_any_e2b_request_without_paused_cleanup(): void
    {
        $this->http->fake();
        $capabilities = $this->provider->capabilities();
        $handle = new SandboxHandle('sbx-123', 'e2b');

        $this->assertFalse($capabilities->supports(SandboxCapability::Suspend));
        $this->assertFalse($capabilities->supports(SandboxCapability::Resume));

        foreach ([SandboxCapability::Suspend, SandboxCapability::Resume] as $capability) {
            try {
                $capability === SandboxCapability::Suspend
                    ? $this->provider->suspend($handle)
                    : $this->provider->resume($handle);
                $this->fail("Expected E2B {$capability->value} to be unavailable.");
            } catch (UnsupportedSandboxCapabilityException $exception) {
                $this->assertStringContainsString(
                    "does not support [{$capability->value}]",
                    $exception->getMessage(),
                );
            }
        }

        $this->http->assertNothingSent();
    }

    public function test_snapshot_delete_keeps_transient_failures_retryable_and_treats_not_found_as_success(): void
    {
        $statuses = [503, 204, 404];
        $this->http->fake(function () use (&$statuses) {
            return $this->http->response([], array_shift($statuses));
        });

        try {
            $this->provider->deleteSnapshot('snapshot-retry');
            $this->fail('Expected a transient snapshot deletion failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        $this->provider->deleteSnapshot('snapshot-retry');
        $this->provider->deleteSnapshot('snapshot-retry');

        $this->http->assertSentCount(3);
    }

    public function test_snapshot_retry_reconciles_creation_after_the_provider_response_is_lost(): void
    {
        $created = [];
        $deleted = [];
        $postCount = 0;

        $this->http->fake(function (Request $request) use (&$created, &$deleted, &$postCount) {
            $url = parse_url($request->url());
            $path = (string) ($url['path'] ?? '');

            if ($request->method() === 'GET' && $path === '/snapshots') {
                parse_str((string) ($url['query'] ?? ''), $query);
                $name = (string) ($query['name'] ?? '');

                return $this->http->response(array_values(array_filter(
                    $created,
                    static fn (array $snapshot): bool => $snapshot['operation_name'] === $name,
                )));
            }

            if ($request->method() === 'POST' && str_ends_with($path, '/snapshots')) {
                $postCount++;
                $name = (string) ($request['name'] ?? '');
                $snapshotId = $name === ''
                    ? "unnamed-snapshot-{$postCount}:default"
                    : "test-team/{$name}:default";
                $created[] = [
                    'snapshotID' => $snapshotId,
                    'names' => [$snapshotId],
                    'operation_name' => $name,
                ];

                if ($postCount === 1) {
                    throw new ConnectionException('snapshot persisted but response was lost');
                }

                return $this->http->response(end($created), 201);
            }

            if ($request->method() === 'DELETE' && str_starts_with($path, '/templates/')) {
                $snapshotId = rawurldecode(substr($path, strlen('/templates/')));

                foreach ($created as $index => $snapshot) {
                    if ($snapshot['snapshotID'] === $snapshotId) {
                        $deleted[] = $index;
                    }
                }

                return $this->http->response([], 204);
            }

            return $this->http->response([], 404);
        });

        $handle = new SandboxHandle('sbx-123', 'e2b');

        try {
            $this->provider->snapshotForOperation($handle, 'workflow-snapshot-operation');
            $this->fail('Expected the first snapshot acknowledgement to be lost.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('response was lost', $exception->getMessage());
        }

        $snapshotId = $this->provider->snapshotForOperation($handle, 'workflow-snapshot-operation');
        $this->provider->deleteSnapshot($snapshotId);

        $this->assertSame(1, $postCount, 'The retry must discover the first persistent snapshot.');
        $this->assertNotSame('', $created[0]['operation_name']);
        $this->assertSame(array_keys($created), $deleted);
    }

    public function test_snapshot_reconciliation_rejects_an_empty_operation_identity(): void
    {
        $this->http->fake();

        try {
            $this->provider->snapshotForOperation(new SandboxHandle('sbx-123', 'e2b'), '');
            $this->fail('Expected an empty snapshot operation identity to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('must not be empty', $exception->getMessage());
        }

        $this->http->assertNothingSent();
    }

    public function test_shell_dispatch_uses_documented_envd_connect_contract_and_stable_tag(): void
    {
        $stream = $this->connectEnvelope(['event' => ['data' => ['stdout' => base64_encode('hello')]]])
            .$this->connectEnvelope(['event' => ['data' => ['stderr' => base64_encode('warning')]]])
            .$this->connectEnvelope(['event' => ['end' => ['exitCode' => 7]]])
            .$this->connectEnvelope([], endStream: true);

        $this->http->fake([
            'https://api.e2b.app/sandboxes/sbx-123' => $this->http->response([
                'sandboxID' => 'sbx-123',
                'envdAccessToken' => 'envd-token',
            ]),
            'https://sandbox.e2b.app/process.Process/Start' => $this->http->response(
                $stream,
                200,
                ['Content-Type' => 'application/connect+json'],
            ),
        ]);

        $result = $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-stable-123', 'shell', ['command' => 'echo hello']),
        );

        $this->assertSame(7, $result->exitCode);
        $this->assertSame('hello', $result->stdout);
        $this->assertSame('warning', $result->stderr);
        $this->http->assertSent(function (Request $request): bool {
            $payload = json_encode([
                'process' => [
                    'cmd' => '/bin/bash',
                    'args' => ['-lc', 'echo hello'],
                    'cwd' => '/home/user',
                ],
                'tag' => 'operation-stable-123',
                'stdin' => false,
            ], JSON_THROW_ON_ERROR);
            $body = $request->body();

            return $request->url() === 'https://sandbox.e2b.app/process.Process/Start'
                && $request->hasHeader('E2b-Sandbox-Id', 'sbx-123')
                && $request->hasHeader('E2b-Sandbox-Port', '49983')
                && $request->hasHeader('X-Access-Token', 'envd-token')
                && $request->hasHeader('Connect-Protocol-Version', '1')
                && $request->hasHeader('Content-Type', 'application/connect+json')
                && $body === chr(0).pack('N', strlen($payload)).$payload
                && json_decode(substr($body, 5), true, flags: JSON_THROW_ON_ERROR) === [
                    'process' => [
                        'cmd' => '/bin/bash',
                        'args' => ['-lc', 'echo hello'],
                        'cwd' => '/home/user',
                    ],
                    'tag' => 'operation-stable-123',
                    'stdin' => false,
                ];
        });
    }

    public function test_shell_dispatch_rejects_environment_values_that_are_not_a_string_map(): void
    {
        $this->http->fake([
            'https://api.e2b.app/sandboxes/sbx-123' => $this->http->response([
                'sandboxID' => 'sbx-123',
                'envdAccessToken' => 'envd-token',
            ]),
        ]);

        foreach ([
            'not-an-array',
            ['indexed-value'],
            ['VALID_KEY' => 123],
        ] as $index => $environment) {
            $result = $this->provider->execute(
                new SandboxHandle('sbx-123', 'e2b'),
                new SandboxToolCall(
                    "operation-invalid-env-{$index}",
                    'shell',
                    ['command' => 'true', 'env' => $environment],
                ),
            );

            $this->assertSame(1, $result->exitCode);
            $this->assertSame('shell tool env argument must be a string map', $result->stderr);
        }

        $this->http->assertNotSent(
            fn (Request $request): bool => $request->url() === 'https://sandbox.e2b.app/process.Process/Start',
        );
    }

    public function test_shell_dispatch_encodes_string_environment_maps_as_json_objects(): void
    {
        $stream = $this->connectEnvelope(['event' => ['end' => ['exitCode' => 0]]])
            .$this->connectEnvelope([], endStream: true);
        $this->fakeShellResponse($stream);

        $result = $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall(
                'operation-env-map-123',
                'shell',
                ['command' => 'env', 'env' => ['APP_ENV' => 'testing']],
            ),
        );

        $this->assertTrue($result->succeeded());
        $this->http->assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://sandbox.e2b.app/process.Process/Start') {
                return false;
            }

            $payload = json_decode(substr($request->body(), 5), false, flags: JSON_THROW_ON_ERROR);

            return is_object($payload)
                && is_object($payload->process ?? null)
                && is_object($payload->process->envs ?? null)
                && ($payload->process->envs->APP_ENV ?? null) === 'testing';
        });
    }

    public function test_shell_dispatch_rejects_stream_completion_without_a_process_result(): void
    {
        $stream = $this->connectEnvelope(['event' => ['data' => ['stdout' => base64_encode('partial')]]])
            .$this->connectEnvelope([], endStream: true);
        $this->fakeShellResponse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ended before the process result was received');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-dropped-stream-123', 'shell', ['command' => 'echo partial']),
        );
    }

    public function test_shell_dispatch_rejects_connect_error_envelopes(): void
    {
        $stream = $this->connectEnvelope([
            'error' => [
                'code' => 'resource_exhausted',
                'message' => 'process quota exceeded',
            ],
        ], endStream: true);

        $this->fakeShellResponse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resource_exhausted: process quota exceeded');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-error-123', 'shell', ['command' => 'false']),
        );
    }

    public function test_shell_dispatch_rejects_explicitly_null_end_stream_errors(): void
    {
        $this->fakeShellResponse($this->connectEnvelope(['error' => null], endStream: true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid end-stream error');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-null-error-123', 'shell', ['command' => 'true']),
        );
    }

    public function test_shell_dispatch_rejects_empty_end_stream_error_codes(): void
    {
        $this->fakeShellResponse($this->connectEnvelope([
            'error' => ['code' => ''],
        ], endStream: true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid end-stream error code');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-empty-error-code-123', 'shell', ['command' => 'true']),
        );
    }

    public function test_shell_dispatch_rejects_null_end_stream_error_codes(): void
    {
        $this->fakeShellResponse($this->connectEnvelope([
            'error' => ['code' => null],
        ], endStream: true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid end-stream error code');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-null-error-code-123', 'shell', ['command' => 'true']),
        );
    }

    public function test_shell_dispatch_rejects_non_string_end_stream_error_codes(): void
    {
        $this->fakeShellResponse($this->connectEnvelope([
            'error' => ['code' => 8],
        ], endStream: true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid end-stream error code');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-numeric-error-code-123', 'shell', ['command' => 'true']),
        );
    }

    public function test_shell_dispatch_rejects_invalid_protobuf_json_bytes(): void
    {
        $stream = $this->connectEnvelope(['event' => ['data' => ['stdout' => 'not-base64!']]])
            .$this->connectEnvelope([], endStream: true);

        $this->fakeShellResponse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid protobuf JSON bytes');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-malformed-bytes-123', 'shell', ['command' => 'true']),
        );
    }

    public function test_shell_dispatch_rejects_truncated_connect_envelopes(): void
    {
        $this->fakeShellResponse(chr(0).pack('N', 20).'{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ended inside an envelope payload');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall('operation-malformed-123', 'shell', ['command' => 'true']),
        );
    }

    public function test_write_file_uses_documented_multipart_file_and_path_query_contract(): void
    {
        $this->http->fake([
            'https://api.e2b.app/sandboxes/sbx-123' => $this->http->response([
                'sandboxID' => 'sbx-123',
                'envdAccessToken' => 'envd-token',
            ]),
            'https://sandbox.e2b.app/files*' => $this->http->response([], 201),
        ]);

        $result = $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall(
                'operation-write-123',
                'write_file',
                ['path' => '/home/user/state.txt', 'contents' => 'durable state'],
            ),
        );

        $this->assertTrue($result->succeeded());
        $this->http->assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://sandbox.e2b.app/files?path=%2Fhome%2Fuser%2Fstate.txt'
                && $request->isMultipart()
                && $request->hasFile('file', 'durable state', 'state.txt')
                && $request->hasHeader('X-Metadata-durable-operation-id', 'operation-write-123');
        });
    }

    public function test_read_file_maps_the_documented_missing_path_response_to_a_failed_result(): void
    {
        $this->http->fake([
            'https://api.e2b.app/sandboxes/sbx-123' => $this->http->response([
                'sandboxID' => 'sbx-123',
                'envdAccessToken' => 'envd-token',
            ]),
            'https://sandbox.e2b.app/files*' => $this->http->response([
                'message' => "path '/home/user/missing.txt' does not exist",
                'code' => 404,
            ], 404),
        ]);

        $result = $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall(
                'operation-read-missing-123',
                'read_file',
                ['path' => '/home/user/missing.txt'],
            ),
        );

        $this->assertSame(1, $result->exitCode);
        $this->assertSame('', $result->stdout);
        $this->assertSame('File not found: /home/user/missing.txt', $result->stderr);
        $this->http->assertSentCount(2);
        $this->http->assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://sandbox.e2b.app/files?path=%2Fhome%2Fuser%2Fmissing.txt'
                && $request->hasHeader('E2b-Sandbox-Id', 'sbx-123')
                && $request->hasHeader('E2b-Sandbox-Port', '49983')
                && $request->hasHeader('X-Access-Token', 'envd-token');
        });
    }

    public function test_read_file_keeps_an_unrecognized_data_plane_404_as_sandbox_loss(): void
    {
        $this->http->fake([
            'https://api.e2b.app/sandboxes/sbx-123' => $this->http->response([
                'sandboxID' => 'sbx-123',
                'envdAccessToken' => 'envd-token',
            ]),
            'https://sandbox.e2b.app/files*' => $this->http->response([
                'message' => 'sandbox not found',
                'code' => 404,
                'sandboxID' => 'sbx-123',
            ], 404),
        ]);

        $this->expectException(SandboxGoneException::class);
        $this->expectExceptionMessage('read file returned 404');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall(
                'operation-read-gone-123',
                'read_file',
                ['path' => '/home/user/missing.txt'],
            ),
        );
    }

    public function test_read_file_keeps_a_management_404_as_sandbox_loss(): void
    {
        $this->http->fake([
            'https://api.e2b.app/sandboxes/sbx-123' => $this->http->response([
                'message' => 'sandbox not found',
            ], 404),
        ]);

        $this->expectException(SandboxGoneException::class);
        $this->expectExceptionMessage('get sandbox returned 404');

        $this->provider->execute(
            new SandboxHandle('sbx-123', 'e2b'),
            new SandboxToolCall(
                'operation-read-gone-123',
                'read_file',
                ['path' => '/home/user/missing.txt'],
            ),
        );
    }

    public function test_capabilities_state_e2b_does_not_deduplicate_operations(): void
    {
        $capabilities = $this->provider->capabilities();

        $this->assertSame(DeliveryGuarantee::AtLeastOnceEffects, $capabilities->deliveryGuarantee);
        $this->assertFalse($capabilities->supports(SandboxCapability::OperationDeduplication));
        $this->assertTrue($capabilities->supports(SandboxCapability::LeaseReconciliation));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function connectEnvelope(array $payload, bool $endStream = false): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return chr($endStream ? 0x02 : 0x00).pack('N', strlen($json)).$json;
    }

    private function fakeShellResponse(string $stream): void
    {
        $this->http->fake([
            'https://api.e2b.app/sandboxes/sbx-123' => $this->http->response([
                'sandboxID' => 'sbx-123',
                'envdAccessToken' => 'envd-token',
            ]),
            'https://sandbox.e2b.app/process.Process/Start' => $this->http->response(
                $stream,
                200,
                ['Content-Type' => 'application/connect+json'],
            ),
        ]);
    }
}
