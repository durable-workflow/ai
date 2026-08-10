<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Contracts\V1;

enum DeliveryGuarantee: string
{
    /** Repeated delivery of one operation id has one observable tool effect. */
    case DeduplicatedOperations = 'deduplicated_operations';

    /** A lost acknowledgement can repeat the tool effect. */
    case AtLeastOnceEffects = 'at_least_once_effects';
}
