<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Providers;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\ProviderCapabilities;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SnapshotDeletingSandboxProvider;
use DurableWorkflow\AI\Exceptions\PermanentSandboxProvisionException;
use DurableWorkflow\AI\Exceptions\SandboxGoneException;
use DurableWorkflow\AI\Exceptions\SandboxProvisionException;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxToolCall;
use DurableWorkflow\AI\SandboxToolResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * E2B Cloud adapter implemented against E2B's documented management, envd
 * filesystem, and Connect process HTTP APIs. E2B publishes official JavaScript
 * and Python SDKs; this PHP adapter intentionally uses those HTTP contracts.
 */
final class E2bSandboxProvider implements SnapshotDeletingSandboxProvider
{
    private const ENVD_PORT = 49983;

    private const MAXIMUM_LEASE_SECONDS = 86400;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $templateId,
        private readonly string $apiBaseUrl,
        private readonly string $sandboxBaseUrl,
        private readonly int $timeoutSeconds,
        private readonly HttpFactory $http,
    ) {}

    public function name(): string
    {
        return 'e2b';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supported: [
                SandboxCapability::Snapshot,
                SandboxCapability::SnapshotDeletion,
                SandboxCapability::Restore,
                SandboxCapability::LeaseReconciliation,
            ],
            deliveryGuarantee: DeliveryGuarantee::AtLeastOnceEffects,
            idempotentDestroy: true,
            maximumLeaseSeconds: self::MAXIMUM_LEASE_SECONDS,
        );
    }

    public function provision(array $options = []): SandboxHandle
    {
        if ($this->apiKey === '') {
            throw new PermanentSandboxProvisionException(
                'E2B requires an API key in durable-workflow-ai.drivers.e2b.api_key.',
            );
        }

        $leaseTtl = max(1, min(
            (int) ($options['lease_ttl_seconds'] ?? 900),
            self::MAXIMUM_LEASE_SECONDS,
        ));

        $response = $this->managementRequest(
            'POST',
            '/sandboxes',
            [
                'templateID' => (string) ($options['template_id'] ?? $this->templateId),
                'timeout' => $leaseTtl,
                'secure' => true,
                'metadata' => is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
            ],
        );

        if (! $response->successful()) {
            $message = "E2B provision failed: HTTP {$response->status()} {$response->body()}";

            if ($this->isPermanentProvisionFailure($response->status())) {
                throw new PermanentSandboxProvisionException($message);
            }

            throw new SandboxProvisionException($message);
        }

        $body = $response->json();
        $id = is_array($body) ? (string) ($body['sandboxID'] ?? '') : '';

        if ($id === '') {
            throw new SandboxProvisionException('E2B provision response did not include sandboxID.');
        }

        return new SandboxHandle(
            id: $id,
            provider: $this->name(),
            metadata: [
                'template_id' => is_array($body)
                    ? (string) ($body['templateID'] ?? $this->templateId)
                    : $this->templateId,
            ],
        );
    }

    public function execute(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult
    {
        $accessToken = $this->accessToken($handle);

        return match ($call->type) {
            'shell' => $this->executeShell($handle, $call, $accessToken),
            'write_file' => $this->writeFile($handle, $call, $accessToken),
            'read_file' => $this->readFile($handle, $call, $accessToken),
            default => new SandboxToolResult(
                exitCode: 1,
                stderr: "Unsupported E2B tool type: {$call->type}",
            ),
        };
    }

    public function suspend(SandboxHandle $handle): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::Suspend, $this->name());
        $this->requireSuccessful(
            $this->managementRequest('POST', "/sandboxes/{$handle->id}/pause"),
            $handle,
            'pause',
        );

        return $handle;
    }

    public function resume(SandboxHandle $handle): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::Resume, $this->name());
        $this->requireSuccessful(
            $this->managementRequest(
                'POST',
                "/sandboxes/{$handle->id}/connect",
                ['timeout' => min(900, self::MAXIMUM_LEASE_SECONDS)],
            ),
            $handle,
            'connect',
        );

        return $handle;
    }

    public function snapshot(SandboxHandle $handle): string
    {
        $this->capabilities()->require(SandboxCapability::Snapshot, $this->name());
        $response = $this->managementClient()
            ->withBody('{}', 'application/json')
            ->post("/sandboxes/{$handle->id}/snapshots");
        $this->requireSuccessful($response, $handle, 'snapshot');

        $body = $response->json();
        $snapshotId = is_array($body) ? (string) ($body['snapshotID'] ?? '') : '';

        if ($snapshotId === '') {
            throw new RuntimeException('E2B snapshot response did not include snapshotID.');
        }

        return $snapshotId;
    }

    public function restore(string $snapshotId): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::Restore, $this->name());

        // E2B snapshot IDs are template identifiers accepted by POST /sandboxes.
        return $this->provision(['template_id' => $snapshotId]);
    }

    public function deleteSnapshot(string $snapshotId): void
    {
        $this->capabilities()->require(SandboxCapability::SnapshotDeletion, $this->name());
        $path = '/templates/'.rawurlencode($snapshotId);
        $response = $this->managementRequest('DELETE', $path);

        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "E2B snapshot delete failed: HTTP {$response->status()} {$response->body()}",
            );
        }
    }

    public function renewLease(SandboxHandle $handle, int $ttlSeconds): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::LeaseReconciliation, $this->name());
        $ttlSeconds = max(1, min($ttlSeconds, self::MAXIMUM_LEASE_SECONDS));
        $this->requireSuccessful(
            $this->managementRequest(
                'POST',
                "/sandboxes/{$handle->id}/timeout",
                ['timeout' => $ttlSeconds],
            ),
            $handle,
            'set timeout',
        );

        return $handle;
    }

    public function destroy(SandboxHandle $handle): void
    {
        $response = $this->managementRequest('DELETE', "/sandboxes/{$handle->id}");

        if ($response->status() === 404) {
            return;
        }

        $this->requireSuccessful($response, $handle, 'delete');
    }

    private function executeShell(
        SandboxHandle $handle,
        SandboxToolCall $call,
        string $accessToken,
    ): SandboxToolResult {
        $command = $call->args['command'] ?? null;

        if (! is_string($command) || $command === '') {
            return new SandboxToolResult(1, stderr: 'shell tool requires a command argument');
        }

        $environment = $call->args['env'] ?? [];

        if (! is_array($environment)) {
            return new SandboxToolResult(1, stderr: 'shell tool env argument must be a string map');
        }

        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                return new SandboxToolResult(1, stderr: 'shell tool env argument must be a string map');
            }
        }

        $startRequest = [
            'process' => [
                'cmd' => '/bin/bash',
                'args' => ['-lc', $command],
                'cwd' => (string) ($call->args['cwd'] ?? '/home/user'),
            ],
            'tag' => $call->operationId,
            'stdin' => false,
        ];

        if ($environment !== []) {
            $startRequest['process']['envs'] = $environment;
        }

        $payload = json_encode($startRequest, JSON_THROW_ON_ERROR);
        $requestEnvelope = chr(0).pack('N', strlen($payload)).$payload;

        $response = $this->sandboxClient($handle, $accessToken)
            ->withHeaders([
                'Authorization' => 'Basic '.base64_encode(
                    (string) ($call->args['user'] ?? 'user').':',
                ),
                'Connect-Protocol-Version' => '1',
            ])
            ->withBody($requestEnvelope, 'application/connect+json')
            ->post('/process.Process/Start');

        $this->requireSuccessful($response, $handle, 'execute command');

        return E2bConnectStreamParser::parse($response->body());
    }

    private function writeFile(
        SandboxHandle $handle,
        SandboxToolCall $call,
        string $accessToken,
    ): SandboxToolResult {
        $path = $call->args['path'] ?? null;

        if (! is_string($path) || $path === '') {
            return new SandboxToolResult(1, stderr: 'write_file requires a path argument');
        }

        $contents = (string) ($call->args['contents'] ?? '');
        $response = $this->sandboxClient($handle, $accessToken)
            ->withHeaders(['X-Metadata-durable-operation-id' => $call->operationId])
            ->attach('file', $contents, basename($path))
            ->post('/files?'.http_build_query(['path' => $path]));
        $this->requireSuccessful($response, $handle, 'write file');

        return new SandboxToolResult(0, 'wrote '.$path.' '.strlen($contents).' bytes');
    }

    private function readFile(
        SandboxHandle $handle,
        SandboxToolCall $call,
        string $accessToken,
    ): SandboxToolResult {
        $path = $call->args['path'] ?? null;

        if (! is_string($path) || $path === '') {
            return new SandboxToolResult(1, stderr: 'read_file requires a path argument');
        }

        $response = $this->sandboxClient($handle, $accessToken)
            ->get('/files', ['path' => $path]);

        if ($this->isMissingFileResponse($response)) {
            return new SandboxToolResult(1, stderr: "File not found: {$path}");
        }

        $this->requireSuccessful($response, $handle, 'read file');

        return new SandboxToolResult(0, $response->body());
    }

    private function isMissingFileResponse(Response $response): bool
    {
        if ($response->status() !== 404) {
            return false;
        }

        $body = $response->json();
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        return is_array($body)
            && ($body['code'] ?? null) === 404
            && is_string($message)
            && preg_match("/^path '.+' does not exist$/D", $message) === 1;
    }

    private function accessToken(SandboxHandle $handle): string
    {
        $response = $this->managementRequest('GET', "/sandboxes/{$handle->id}");
        $this->requireSuccessful($response, $handle, 'get sandbox');
        $body = $response->json();
        $token = is_array($body) ? (string) ($body['envdAccessToken'] ?? '') : '';

        if ($token === '') {
            throw new RuntimeException(
                "E2B sandbox {$handle->id} did not return envdAccessToken for secure access.",
            );
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function managementRequest(string $method, string $path, array $payload = []): Response
    {
        try {
            return match (strtoupper($method)) {
                'GET' => $this->managementClient()->get($path, $payload),
                'DELETE' => $this->managementClient()->delete($path),
                default => $this->managementClient()->send($method, $path, ['json' => $payload]),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                "E2B {$method} {$path} failed: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    private function requireSuccessful(
        Response $response,
        SandboxHandle $handle,
        string $operation,
    ): void {
        if ($response->status() === 404) {
            throw new SandboxGoneException(
                "E2B sandbox {$handle->id} no longer exists ({$operation} returned 404).",
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "E2B {$operation} failed: HTTP {$response->status()} {$response->body()}",
            );
        }
    }

    private function isPermanentProvisionFailure(int $status): bool
    {
        return $status >= 400
            && $status < 500
            && ! in_array($status, [408, 409, 425, 429], true);
    }

    private function managementClient(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->apiBaseUrl, '/'))
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds);
    }

    private function sandboxClient(SandboxHandle $handle, string $accessToken): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->sandboxBaseUrl, '/'))
            ->withHeaders([
                'E2b-Sandbox-Id' => $handle->id,
                'E2b-Sandbox-Port' => (string) self::ENVD_PORT,
                'X-Access-Token' => $accessToken,
            ])
            ->timeout($this->timeoutSeconds);
    }
}
