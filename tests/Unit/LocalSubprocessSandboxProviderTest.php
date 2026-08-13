<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Unit;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use DurableWorkflow\AI\Providers\LocalSubprocessSandboxProvider;
use DurableWorkflow\AI\SandboxToolCall;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LocalSubprocessSandboxProviderTest extends TestCase
{
    private string $workspaceRoot;

    private string $snapshotRoot;

    private LocalSubprocessSandboxProvider $provider;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->workspaceRoot = sys_get_temp_dir().'/dwi-ai-workspaces-'.$suffix;
        $this->snapshotRoot = sys_get_temp_dir().'/dwi-ai-snapshots-'.$suffix;
        $this->provider = new LocalSubprocessSandboxProvider(
            $this->workspaceRoot,
            $this->snapshotRoot,
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workspaceRoot);
        $this->deleteDirectory($this->snapshotRoot);
    }

    public function test_workspace_snapshot_restore_lease_and_idempotent_destroy_round_trip(): void
    {
        $handle = $this->provider->provision();
        $this->provider->renewLease($handle, 30);

        $write = $this->provider->execute(
            $handle,
            new SandboxToolCall('write-1', 'write_file', ['path' => 'state.txt', 'contents' => 'durable']),
        );
        $this->assertTrue($write->succeeded());

        $snapshot = $this->provider->snapshot($handle);
        $this->provider->destroy($handle);
        $this->provider->destroy($handle);

        $restored = $this->provider->restore($snapshot);
        $this->provider->deleteSnapshot($snapshot);
        $this->provider->deleteSnapshot($snapshot);
        $read = $this->provider->execute(
            $restored,
            new SandboxToolCall('read-1', 'read_file', ['path' => 'state.txt']),
        );

        $this->assertSame('durable', $read->stdout);
        $this->assertFileDoesNotExist($this->snapshotRoot.'/'.$snapshot.'.tar');
        $this->assertFileDoesNotExist($this->workspaceRoot.'/.leases/'.$handle->id.'.json');
    }

    public function test_snapshot_operation_retry_reuses_the_persistent_archive(): void
    {
        $handle = $this->provider->provision();
        $this->provider->execute(
            $handle,
            new SandboxToolCall('write-1', 'write_file', ['path' => 'state.txt', 'contents' => 'durable']),
        );

        $first = $this->provider->snapshotForOperation($handle, 'snapshot-operation-1');
        $second = $this->provider->snapshotForOperation($handle, 'snapshot-operation-1');

        $this->assertSame($first, $second);
        $this->assertFileExists($this->snapshotRoot.'/'.$first.'.tar');
        $this->assertCount(
            1,
            iterator_to_array(new FilesystemIterator($this->snapshotRoot, FilesystemIterator::SKIP_DOTS)),
        );
    }

    public function test_capabilities_expose_at_least_once_development_boundary_and_reject_suspend(): void
    {
        $capabilities = $this->provider->capabilities();

        $this->assertSame(DeliveryGuarantee::AtLeastOnceEffects, $capabilities->deliveryGuarantee);
        $this->assertFalse($capabilities->supports(SandboxCapability::OperationDeduplication));
        $this->assertTrue($capabilities->supports(SandboxCapability::LeaseReconciliation));
        $this->assertTrue($capabilities->supports(SandboxCapability::SnapshotDeletion));

        $this->expectException(UnsupportedSandboxCapabilityException::class);
        $this->provider->suspend($this->provider->provision());
    }

    public function test_tool_dispatch_cannot_inject_destructive_sandbox_loss(): void
    {
        $handle = $this->provider->provision();
        $result = $this->provider->execute(
            $handle,
            new SandboxToolCall('evict-1', 'evict'),
        );

        $write = $this->provider->execute(
            $handle,
            new SandboxToolCall('write-after-evict', 'write_file', [
                'path' => 'state.txt',
                'contents' => 'still present',
            ]),
        );

        $this->assertSame(1, $result->exitCode);
        $this->assertSame('Unsupported tool type: evict', $result->stderr);
        $this->assertTrue($write->succeeded());
    }

    public function test_path_traversal_is_rejected(): void
    {
        $result = $this->provider->execute(
            $this->provider->provision(),
            new SandboxToolCall('unsafe-write', 'write_file', [
                'path' => '../outside.txt',
                'contents' => 'no',
            ]),
        );

        $this->assertSame(1, $result->exitCode);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $path) {
            if ($path->isDir() && ! $path->isLink()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }

        @rmdir($directory);
    }
}
