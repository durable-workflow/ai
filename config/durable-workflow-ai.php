<?php

declare(strict_types=1);

return [
    'default' => env('DURABLE_AI_SANDBOX_DRIVER', 'local'),

    // Every provisioned sandbox receives a renewable, bounded lease. A failed
    // workflow finalizer can therefore leave a resource alive only until this
    // provider-enforced deadline.
    'lease_ttl_seconds' => (int) env('DURABLE_AI_SANDBOX_LEASE_TTL', 900),

    'drivers' => [
        'local' => [
            'workspace_root' => env(
                'DURABLE_AI_LOCAL_WORKSPACE_ROOT',
                storage_path('durable-workflow-ai/workspaces'),
            ),
            'snapshot_root' => env(
                'DURABLE_AI_LOCAL_SNAPSHOT_ROOT',
                storage_path('durable-workflow-ai/snapshots'),
            ),
        ],

        'e2b' => [
            'api_key' => env('E2B_API_KEY', ''),
            'template_id' => env('E2B_TEMPLATE_ID', 'base'),
            'api_base_url' => env('E2B_API_BASE_URL', 'https://api.e2b.app'),
            'sandbox_base_url' => env('E2B_SANDBOX_BASE_URL', 'https://sandbox.e2b.app'),
            'timeout_seconds' => (int) env('E2B_REQUEST_TIMEOUT_SECONDS', 300),
        ],
    ],
];
