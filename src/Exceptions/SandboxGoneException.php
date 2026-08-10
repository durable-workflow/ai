<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Exceptions;

use RuntimeException;
use Workflow\Exceptions\NonRetryableExceptionContract;

final class SandboxGoneException extends RuntimeException implements NonRetryableExceptionContract {}
