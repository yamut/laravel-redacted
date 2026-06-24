<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    |
    | Which scheme is used when no scheme prefix is specified. In production
    | this would typically be 'ssm' or 'asm'. For local development, 'env'
    | or 'array' work without any cloud credentials.
    |
    */
    'default' => env('REDACTED_DRIVER', 'env'),

    /*
    |--------------------------------------------------------------------------
    | Driver Configurations
    |--------------------------------------------------------------------------
    |
    | Each scheme maps to a driver config block. The 'driver' key must match
    | a create{Driver}Driver() method in RedactedManager. Auth credentials
    | should remain in .env — do not resolve them via redacted() itself.
    |
    */
    'drivers' => [

        'ssm' => [
            'driver'  => 'ssm',
            'region'  => env('AWS_DEFAULT_REGION', 'us-east-1'),
            // 'key'    => env('AWS_ACCESS_KEY_ID'),
            // 'secret' => env('AWS_SECRET_ACCESS_KEY'),
            // Leave key/secret unset to use IAM role / ECS task role (ambient).
        ],

        'asm' => [
            'driver'  => 'asm',
            'region'  => env('AWS_DEFAULT_REGION', 'us-east-1'),
            // 'key'    => env('AWS_ACCESS_KEY_ID'),
            // 'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],

        'akv' => [
            'driver'               => 'akv',
            'vault_url'            => env('AZURE_KEY_VAULT_URL'),      // e.g. https://myvault.vault.azure.net
            'tenant_id'            => env('AZURE_TENANT_ID'),
            'client_id'            => env('AZURE_CLIENT_ID'),
            'client_secret'        => env('AZURE_CLIENT_SECRET'),
            'use_managed_identity' => false,
            // Set use_managed_identity => true to skip client-credential flow.
        ],

        'gcp' => [
            'driver'  => 'gcp',
            'project' => env('GOOGLE_CLOUD_PROJECT'),
            // 'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
            // Leave unset to use Application Default Credentials (ambient).
        ],

        'vault' => [
            'driver'     => 'vault',
            'address'    => env('VAULT_ADDR', 'https://127.0.0.1:8200'),
            'auth'       => env('VAULT_AUTH', 'token'),  // 'token' or 'approle'
            'token'      => env('VAULT_TOKEN'),           // for token auth
            // 'role_id'    => env('VAULT_ROLE_ID'),      // for AppRole auth
            // 'secret_id'  => env('VAULT_SECRET_ID'),
            // 'approle_mount' => 'approle',              // AppRole mount path
            'kv_version' => env('VAULT_KV_VERSION', 2),  // 1 or 2
        ],

        'infisical' => [
            'driver'         => 'infisical',
            'base_url'       => env('INFISICAL_URL', 'https://us.infisical.com'),
            'client_id'      => env('INFISICAL_CLIENT_ID'),
            'client_secret'  => env('INFISICAL_CLIENT_SECRET'),
            'workspace_id'   => env('INFISICAL_WORKSPACE_ID'),   // or workspace_slug
            // 'workspace_slug' => env('INFISICAL_WORKSPACE_SLUG'),
            'environment'    => env('INFISICAL_ENVIRONMENT', 'prod'),
            'secret_path'    => env('INFISICAL_SECRET_PATH', '/'),
        ],

        'doppler' => [
            'driver'  => 'doppler',
            'token'   => env('DOPPLER_TOKEN'),
            'project' => env('DOPPLER_PROJECT'),
            'config'  => env('DOPPLER_CONFIG'),  // environment name, e.g. 'prd', 'dev'
        ],

        'env' => [
            'driver' => 'env',
        ],

        'array' => [
            'driver' => 'array',
            'values' => [],  // [path => value] — pre-populate for testing
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Layer 2 of the resolution cache. Values are stored in the named Laravel
    | cache store with the given TTL. The in-process static cache (layer 1)
    | is always active regardless of these settings.
    |
    */
    'cache' => [
        'store'  => env('REDACTED_CACHE_STORE', 'file'),
        'ttl'    => (int) env('REDACTED_CACHE_TTL', 3600),
        // Prefix includes APP_NAME so two apps sharing a Redis/Memcached cluster
        // don't collide. Override with REDACTED_CACHE_PREFIX if needed.
        'prefix' => env('REDACTED_CACHE_PREFIX', 'redacted:' . env('APP_NAME', 'laravel') . ':'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Value Masking
    |--------------------------------------------------------------------------
    |
    | Number of characters revealed when displaying values via redacted:list.
    |
    */
    'mask_length' => 4,

];
