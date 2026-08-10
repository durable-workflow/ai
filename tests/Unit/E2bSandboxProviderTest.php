<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Unit;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Exceptions\PermanentSandboxProvisionException;
use DurableWorkflow\AI\Exceptions\SandboxProvisionException;
use DurableWorkflow\AI\Providers\E2bSandboxProvider;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxToolCall;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

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

    public function test_lifecycle_uses_pause_connect_snapshot_template_timeout_and_idempotent_delete_routes(): void
    {
        $this->http->fake(function (Request $request) {
            return match (true) {
                str_ends_with($request->url(), '/snapshots') => $this->http->response(['snapshotID' => 'snapshot-template:v1'], 201),
                $request->method() === 'POST' && $request->url() === 'https://api.e2b.app/sandboxes' => $this->http->response([
                    'templateID' => 'snapshot-template:v1',
                    'sandboxID' => 'sbx-restored',
                ], 201),
                $request->method() === 'DELETE' => $this->http->response([], 404),
                default => $this->http->response([], 204),
            };
        });

        $handle = new SandboxHandle('sbx-123', 'e2b');
        $this->provider->suspend($handle);
        $this->provider->resume($handle);
        $this->provider->renewLease($handle, 600);
        $snapshot = $this->provider->snapshot($handle);
        $restored = $this->provider->restore($snapshot);
        $this->provider->destroy($handle);

        $this->assertSame('sbx-restored', $restored->id);
        $this->http->assertSentCount(6);
        $this->http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.e2b.app/sandboxes/sbx-123/pause');
        $this->http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.e2b.app/sandboxes/sbx-123/connect' && $request['timeout'] === 900);
        $this->http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.e2b.app/sandboxes/sbx-123/timeout' && $request['timeout'] === 600);
        $this->http->assertSent(fn (Request $request): bool => $request->url() === 'https://api.e2b.app/sandboxes' && $request['templateID'] === 'snapshot-template:v1');
    }

    public function test_shell_dispatch_uses_documented_envd_connect_contract_and_stable_tag(): void
    {
        $stream = implode("\n", [
            json_encode(['event' => ['data' => ['stdout' => base64_encode('hello')]]], JSON_THROW_ON_ERROR),
            json_encode(['event' => ['end' => ['exitCode' => 0]]], JSON_THROW_ON_ERROR),
        ]);

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

        $this->assertSame('hello', $result->stdout);
        $this->http->assertSent(function (Request $request): bool {
            return $request->url() === 'https://sandbox.e2b.app/process.Process/Start'
                && $request->hasHeader('E2b-Sandbox-Id', 'sbx-123')
                && $request->hasHeader('E2b-Sandbox-Port', '49983')
                && $request->hasHeader('X-Access-Token', 'envd-token')
                && $request->hasHeader('Connect-Protocol-Version', '1')
                && $request['tag'] === 'operation-stable-123'
                && $request['process']['cmd'] === '/bin/bash'
                && $request['process']['args'] === ['-lc', 'echo hello'];
        });
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

    public function test_capabilities_state_e2b_does_not_deduplicate_operations(): void
    {
        $capabilities = $this->provider->capabilities();

        $this->assertSame(DeliveryGuarantee::AtLeastOnceEffects, $capabilities->deliveryGuarantee);
        $this->assertFalse($capabilities->supports(SandboxCapability::OperationDeduplication));
        $this->assertTrue($capabilities->supports(SandboxCapability::LeaseReconciliation));
    }
}
