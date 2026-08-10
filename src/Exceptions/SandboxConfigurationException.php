<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Exceptions;

use InvalidArgumentException;
use Workflow\Exceptions\NonRetryableExceptionContract;

final class SandboxConfigurationException extends InvalidArgumentException implements NonRetryableExceptionContract {}
