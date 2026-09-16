<?php

declare(strict_types=1);

return [
    'login' => [
        // Dos limites complementarios: por cuenta dentro del tenant y por IP.
        'attempts_per_minute' => (int) env('LOGIN_ATTEMPTS_PER_MINUTE', 5),
        'attempts_per_ip_per_minute' => (int) env('LOGIN_ATTEMPTS_PER_IP_PER_MINUTE', 30),
    ],

    'mfa' => [
        'required_for_platform' => (bool) env('MFA_REQUIRED_FOR_PLATFORM', true),
        'required_tenant_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'MFA_REQUIRED_TENANT_ROLES',
                'rector,coord_academico,coord_convivencia,coord_combinado,secretaria',
            )),
        ))),
    ],

    'impersonation' => [
        'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 120),
        // Todo acceso administrativo debe quedar ligado a una justificacion.
        'reason_required' => (bool) env('IMPERSONATION_REASON_REQUIRED', true),
    ],

    'oauth' => [
        'state_ttl_minutes' => (int) env('OAUTH_STATE_TTL_MINUTES', 15),
        'cache_store' => env('OAUTH_CACHE_STORE', 'central_database'),
    ],
];
