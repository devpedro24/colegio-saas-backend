<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Cifra los secretos TOTP en reposo (pendiente de Fase 0).
 *
 * Antes de este comando el secreto se guardaba en TEXTO PLANO (oculto solo via
 * $hidden). El cast 'encrypted' del modelo User ya esta activo; este comando
 * convierte los valores legacy para que el login no falle al descifrar:
 *
 *   php artisan totp:encrypt-secrets            # plataforma + todos los colegios
 *   php artisan totp:encrypt-secrets --tenant=san-jose
 *
 * Es idempotente: los valores ya cifrados (prefijo eyJ) se ignoran.
 */
class EncryptTotpSecrets extends Command
{
    protected $signature = 'totp:encrypt-secrets {--tenant= : Slug de un colegio especifico}';

    protected $description = 'Cifra en reposo los secretos TOTP guardados en texto plano (plataforma + colegios).';

    public function handle(): int
    {
        $total = 0;

        // 1. Usuarios de PLATAFORMA (BD central).
        $total += $this->encryptOn(DB::table('users'));

        // 2. Usuarios de cada COLEGIO (BD del tenant).
        $query = Tenant::query();
        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }

        foreach ($query->get() as $tenant) {
            $count = $tenant->run(function () {
                return $this->encryptOn(DB::table('users'));
            });

            if ($count > 0) {
                $this->info("Colegio {$tenant->slug}: {$count} secreto(s) cifrado(s).");
            }

            $total += $count;
        }

        $this->info("Listo. {$total} secreto(s) TOTP cifrado(s) en reposo.");

        return self::SUCCESS;
    }

    /**
     * Cifra los valores en texto plano de una tabla `users` dada.
     */
    private function encryptOn(Builder $query): int
    {
        $updated = 0;

        $query
            ->whereNotNull('two_factor_secret')
            ->where('two_factor_secret', '!=', '')
            ->orderBy('id')
            ->each(function ($row) use (&$updated) {
                $secret = (string) $row->two_factor_secret;

                // Ya cifrado: el payload de Laravel arranca con el prefijo base64 de JSON (eyJ).
                if (str_starts_with($secret, 'eyJ')) {
                    try {
                        Crypt::decryptString($secret);

                        return; // cifrado correcto, nada que hacer
                    } catch (DecryptException) {
                        // Corrupto: se trata como plano y se recifra abajo.
                    }
                }

                DB::table('users')
                    ->where('id', $row->id)
                    ->update(['two_factor_secret' => Crypt::encryptString($secret)]);

                $updated++;
            });

        return $updated;
    }
}
