<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Exceptions;

use Workflow\Exceptions\NonRetryableExceptionContract;

final class PermanentSandboxProvisionException extends SandboxProvisionException implements NonRetryableExceptionContract {}
