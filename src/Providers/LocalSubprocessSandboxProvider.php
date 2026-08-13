<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Providers;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\ProviderCapabilities;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SnapshotReconcilingSandboxProvider;
use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Exceptions\SandboxGoneException;
use DurableWorkflow\AI\Exceptions\SandboxProvisionException;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxToolCall;
use DurableWorkflow\AI\SandboxToolResult;
use FilesystemIterator;
use InvalidArgumentException;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Development/test-only subprocess workspace provider.
 *
 * This class executes commands with the worker process's own privileges. It is
 * convenient local infrastructure, not a security isolation boundary, and must
 * not be used to execute untrusted code.
 */
final class LocalSubprocessSandboxProvider implements SnapshotReconcilingSandboxProvider
{
    private const MAXIMUM_LEASE_SECONDS = 3600;

    public function __construct(
        private readonly string $workspaceRoot,
        private readonly string $snapshotRoot,
    ) {
        try {
            $this->ensureDir($this->workspaceRoot);
            $this->ensureDir($this->snapshotRoot);
            $this->ensureDir($this->leaseRoot());
        } catch (RuntimeException $exception) {
            throw new SandboxConfigurationException(
                'Local subprocess sandbox paths are not usable: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $this->reconcileExpiredLeases();
    }

    public function name(): string
    {
        return 'local';
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
        $this->reconcileExpiredLeases();

        $id = $this->generateId('sbx');
        $workspace = $this->workspacePath($id);

        if (! @mkdir($workspace, 0o755, true) && ! is_dir($workspace)) {
            throw new SandboxProvisionException(
                "Failed to provision local sandbox workspace at {$workspace}.",
            );
        }

        return new SandboxHandle(
            id: $id,
            provider: $this->name(),
            metadata: ['workspace' => $workspace, 'development_only' => true],
        );
    }

    public function execute(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult
    {
        $workspace = $this->requireWorkspace($handle);

        return match ($call->type) {
            'shell' => $this->runShell($workspace, $call),
            'write_file' => $this->writeFile($workspace, $call),
            'read_file' => $this->readFile($workspace, $call),
            default => new SandboxToolResult(
                exitCode: 1,
                stderr: "Unsupported tool type: {$call->type}",
            ),
        };
    }

    public function suspend(SandboxHandle $handle): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::Suspend, $this->name());

        return $handle;
    }

    public function resume(SandboxHandle $handle): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::Resume, $this->name());

        return $handle;
    }

    public function snapshot(SandboxHandle $handle): string
    {
        $this->capabilities()->require(SandboxCapability::Snapshot, $this->name());
        $snapshotId = $this->generateId('snap');

        return $this->createSnapshot($handle, $snapshotId);
    }

    public function snapshotForOperation(SandboxHandle $handle, string $operationId): string
    {
        $this->capabilities()->require(SandboxCapability::Snapshot, $this->name());

        if ($operationId === '') {
            throw new InvalidArgumentException('Snapshot operation id must not be empty.');
        }

        $snapshotId = 'snap_'.hash('sha256', $operationId);
        $snapshotFile = $this->snapshotPath($snapshotId);

        if (is_file($snapshotFile)) {
            return $snapshotId;
        }

        return $this->createSnapshot($handle, $snapshotId);
    }

    private function createSnapshot(SandboxHandle $handle, string $snapshotId): string
    {
        $workspace = $this->requireWorkspace($handle);
        $snapshotFile = $this->snapshotPath($snapshotId);

        try {
            $tar = new PharData($snapshotFile);
            $tar->buildFromDirectory($workspace);
        } catch (Throwable $exception) {
            @unlink($snapshotFile);

            throw new RuntimeException(
                "Snapshot failed for sandbox {$handle->id}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return $snapshotId;
    }

    public function restore(string $snapshotId): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::Restore, $this->name());
        $snapshotFile = $this->snapshotPath($snapshotId);

        if (! is_file($snapshotFile)) {
            throw new RuntimeException("Snapshot {$snapshotId} was not found.");
        }

        $handle = $this->provision();
        $workspace = $this->requireWorkspace($handle);

        try {
            $tar = new PharData($snapshotFile);
            $tar->extractTo($workspace, overwrite: true);
        } catch (Throwable $exception) {
            $this->destroy($handle);

            throw new RuntimeException(
                "Restore failed for snapshot {$snapshotId}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return $handle;
    }

    public function deleteSnapshot(string $snapshotId): void
    {
        $this->capabilities()->require(SandboxCapability::SnapshotDeletion, $this->name());
        $snapshotFile = $this->snapshotPath($snapshotId);

        if (! @unlink($snapshotFile) && is_file($snapshotFile)) {
            throw new RuntimeException("Failed to delete local snapshot {$snapshotId}.");
        }
    }

    public function renewLease(SandboxHandle $handle, int $ttlSeconds): SandboxHandle
    {
        $this->capabilities()->require(SandboxCapability::LeaseReconciliation, $this->name());
        $this->requireWorkspace($handle);

        $ttlSeconds = max(1, min($ttlSeconds, self::MAXIMUM_LEASE_SECONDS));
        $payload = json_encode([
            'sandbox_id' => $handle->id,
            'expires_at' => time() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($this->leasePath($handle->id), $payload, LOCK_EX) === false) {
            throw new RuntimeException("Failed to renew local sandbox lease for {$handle->id}.");
        }

        return $handle;
    }

    public function destroy(SandboxHandle $handle): void
    {
        $workspace = $this->workspacePath($handle->id);

        if (is_dir($workspace)) {
            $this->deleteRecursive($workspace);
        }

        @unlink($this->leasePath($handle->id));
    }

    private function runShell(string $workspace, SandboxToolCall $call): SandboxToolResult
    {
        $command = $call->args['command'] ?? null;
        $timeout = max(1, (int) ($call->args['timeout'] ?? 30));

        if (! is_string($command) || $command === '') {
            return new SandboxToolResult(exitCode: 1, stderr: 'shell tool requires a command argument');
        }

        $process = Process::fromShellCommandline($command, $workspace, null, null, (float) $timeout);
        $process->run();

        return new SandboxToolResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
        );
    }

    private function writeFile(string $workspace, SandboxToolCall $call): SandboxToolResult
    {
        $relative = $this->safeRelativePath($call->args['path'] ?? null);
        $contents = (string) ($call->args['contents'] ?? '');

        if ($relative === null) {
            return new SandboxToolResult(exitCode: 1, stderr: 'write_file requires a safe relative path');
        }

        $absolute = $workspace.DIRECTORY_SEPARATOR.$relative;
        $this->ensureDir(dirname($absolute));

        if (file_put_contents($absolute, $contents) === false) {
            return new SandboxToolResult(exitCode: 1, stderr: "Failed to write {$relative}");
        }

        return new SandboxToolResult(
            exitCode: 0,
            stdout: 'wrote '.$relative.' '.strlen($contents).' bytes',
        );
    }

    private function readFile(string $workspace, SandboxToolCall $call): SandboxToolResult
    {
        $relative = $this->safeRelativePath($call->args['path'] ?? null);

        if ($relative === null) {
            return new SandboxToolResult(exitCode: 1, stderr: 'read_file requires a safe relative path');
        }

        $absolute = $workspace.DIRECTORY_SEPARATOR.$relative;

        if (! is_file($absolute)) {
            return new SandboxToolResult(exitCode: 1, stderr: "File not found: {$relative}");
        }

        $contents = file_get_contents($absolute);

        return $contents === false
            ? new SandboxToolResult(exitCode: 1, stderr: "Failed to read {$relative}")
            : new SandboxToolResult(exitCode: 0, stdout: $contents);
    }

    private function requireWorkspace(SandboxHandle $handle): string
    {
        $workspace = $this->workspacePath($handle->id);

        if (! is_dir($workspace)) {
            throw new SandboxGoneException("Local sandbox {$handle->id} is gone.");
        }

        return $workspace;
    }

    private function reconcileExpiredLeases(): void
    {
        $now = time();

        foreach (glob($this->leaseRoot().DIRECTORY_SEPARATOR.'*.json') ?: [] as $leaseFile) {
            $payload = json_decode((string) file_get_contents($leaseFile), true);
            $sandboxId = is_array($payload) ? ($payload['sandbox_id'] ?? null) : null;
            $expiresAt = is_array($payload) ? ($payload['expires_at'] ?? null) : null;

            if (! is_string($sandboxId) || ! is_int($expiresAt) || $expiresAt > $now) {
                continue;
            }

            $workspace = $this->workspacePath($sandboxId);

            if (is_dir($workspace)) {
                $this->deleteRecursive($workspace);
            }

            @unlink($leaseFile);
        }
    }

    private function workspacePath(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new RuntimeException('Invalid local sandbox identifier.');
        }

        return $this->workspaceRoot.DIRECTORY_SEPARATOR.$id;
    }

    private function snapshotPath(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new RuntimeException('Invalid local snapshot identifier.');
        }

        return $this->snapshotRoot.DIRECTORY_SEPARATOR.$id.'.tar';
    }

    private function leaseRoot(): string
    {
        return $this->workspaceRoot.DIRECTORY_SEPARATOR.'.leases';
    }

    private function leasePath(string $id): string
    {
        return $this->leaseRoot().DIRECTORY_SEPARATOR.$id.'.json';
    }

    private function safeRelativePath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '/')) {
            return null;
        }

        $segments = preg_split('~[\\\\/]~', $value);

        if ($segments === false || in_array('..', $segments, true) || str_contains($value, "\0")) {
            return null;
        }

        return implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function ensureDir(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }
    }

    private function deleteRecursive(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $path) {
            $pathName = $path->getPathname();

            if ($path->isDir() && ! $path->isLink()) {
                @rmdir($pathName);
            } else {
                @unlink($pathName);
            }
        }

        @rmdir($directory);
    }

    private function generateId(string $prefix): string
    {
        return $prefix.'_'.bin2hex(random_bytes(8));
    }
}
