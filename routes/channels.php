<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canales de broadcasting (WebSockets / Reverb)
|--------------------------------------------------------------------------
|
| AISLAMIENTO: cada colegio tiene su canal privado `tenant.<uuid>`; la
| plataforma (superadmin) tiene `platform`. Un colegio nunca puede escuchar el
| canal de otro. La autorizacion usa el guard `sanctum` (mismo token de la API);
| para los canales de colegio el request llega por el subdominio, asi el tenant
| y el usuario se resuelven en la BD del colegio.
|
*/

// Canal de plataforma: solo el superadministrador.
Broadcast::channel('platform', function ($user) {
    return $user && $user->role === 'superadmin';
}, ['guards' => ['sanctum']]);

// Canal por colegio: cualquier usuario autenticado del tenant actual.
Broadcast::channel('tenant.{tenantId}', function ($user, string $tenantId) {
    return $user && tenant() && (string) tenant()->getKey() === $tenantId;
}, ['guards' => ['sanctum']]);
