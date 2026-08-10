<?php

declare(strict_types=1);

namespace DurableWorkflow\AI;

final class SandboxToolResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function toArray(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            exitCode: (int) ($data['exit_code'] ?? 0),
            stdout: (string) ($data['stdout'] ?? ''),
            stderr: (string) ($data['stderr'] ?? ''),
        );
    }
}
