<?php

declare(strict_types=1);

namespace DurableWorkflow\AI;

use InvalidArgumentException;

final class SandboxToolCall
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly string $operationId,
        public readonly string $type,
        public readonly array $args = [],
    ) {
        if ($operationId === '' || $type === '') {
            throw new InvalidArgumentException('Sandbox tool calls require non-empty operationId and type values.');
        }
    }

    /**
     * @return array{operation_id: string, type: string, args: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId,
            'type' => $this->type,
            'args' => $this->args,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            operationId: (string) ($data['operation_id'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            args: is_array($data['args'] ?? null) ? $data['args'] : [],
        );
    }
}
