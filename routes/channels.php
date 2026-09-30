<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use App\Models\User;
use App\Support\Realtime\TenantChannelName;

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
    return $user && $user->status === User::STATUS_ACTIVE && ! tenant() && $user->role === 'superadmin';
}, ['guards' => ['sanctum']]);

// Canal por colegio: cualquier usuario autenticado del tenant actual.
Broadcast::channel('tenant.{channelToken}', function ($user, string $channelToken) {
    return $user && $user->status === User::STATUS_ACTIVE && tenant()
        && hash_equals(TenantChannelName::tokenForId((string) tenant()->getKey()), $channelToken);
}, ['guards' => ['sanctum']]);
