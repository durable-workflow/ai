<?php

declare(strict_types=1);

namespace DurableWorkflow\AI;

use InvalidArgumentException;

final class SandboxHandle
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $provider,
        public readonly array $metadata = [],
    ) {
        if ($id === '' || $provider === '') {
            throw new InvalidArgumentException('Sandbox handles require non-empty id and provider values.');
        }
    }

    /**
     * @return array{id: string, provider: string, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            provider: (string) ($data['provider'] ?? ''),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
