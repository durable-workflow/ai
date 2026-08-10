<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Exceptions;

use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use LogicException;
use Workflow\Exceptions\NonRetryableExceptionContract;

final class UnsupportedSandboxCapabilityException extends LogicException implements NonRetryableExceptionContract
{
    public static function for(string $provider, SandboxCapability $capability): self
    {
        return new self(
            "Sandbox provider [{$provider}] does not support [{$capability->value}]; the operation was not attempted.",
        );
    }
}
