<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Academico\Sede;
use App\Models\Tenant;
use App\Tenancy\TenantDatabaseName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Renombra las bases de datos de los colegios al patron legible
 * `tenant_<nombre>_<id>` (PostgreSQL `ALTER DATABASE ... RENAME`).
 *
 * Ejecutar con los servidores DETENIDOS: el rename falla si la BD del colegio
 * tiene conexiones activas. Flag `--with-sede-principal`: ademas siembra la
 * Sede Principal en colegios cuya tabla `sedes` este vacia (colegios creados
 * antes de que el provisioning la sembrara).
 */
class RenameTenantDatabases extends Command
{
    protected $signature = 'tenants:rename-databases
        {--dry-run : Muestra los cambios sin ejecutarlos}
        {--with-sede-principal : Siembra la Sede Principal en colegios sin sedes}';

    protected $description = 'Renombra las BDs de los colegios al patron tenant_<nombre>_<id>';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $withSede = (bool) $this->option('with-sede-principal');
        $renamed = 0;
        $wouldRename = 0;
        $unchanged = 0;

        foreach (Tenant::orderBy('name')->cursor() as $tenant) {
            $current = $tenant->getInternal('db_name') ?? $tenant->database()->getName();
            $target = TenantDatabaseName::for($tenant);

            if ($current !== $target) {
                if ($dryRun) {
                    $this->line(sprintf('  [dry-run] %s: %s -> %s', $tenant->slug, $current, $target));
                    $wouldRename++;
                } else {
                    $this->renameDatabase($tenant, $current, $target);
                    $renamed++;
                }
            } else {
                $unchanged++;
            }

            if ($withSede) {
                $seeded = $tenant->run(function () use ($tenant) {
                    if (Sede::query()->count() > 0) {
                        return false;
                    }

                    Sede::create([
                        'nombre' => $tenant->name,
                        'es_principal' => true,
                    ]);

                    return true;
                });

                if ($seeded) {
                    $this->line(sprintf('  Sede Principal sembrada en %s', $tenant->name));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Bases de datos: %d renombradas, %d pendientes (dry-run), %d ya correctas.',
            $renamed,
            $wouldRename,
            $unchanged
        ));
        if ($dryRun) {
            $this->warn('Modo dry-run: no se ejecuto ningun cambio.');
        }

        return self::SUCCESS;
    }

    private function renameDatabase(Tenant $tenant, string $current, string $target): void
    {
        // Se ejecuta sobre la conexion CENTRAL: la BD del colegio no puede ser
        // la base de datos activa de la conexion para poder renombrarla.
        $central = DB::connection(config('tenancy.database.central_connection'));

        // Salvaguarda: nunca renombrar a un nombre ya existente (colision).
        if ($central->table('pg_database')->where('datname', $target)->exists()) {
            $this->warn(sprintf(
                '  SKIP %s: ya existe una BD llamada %s, se conserva %s.',
                $tenant->slug,
                $target,
                $current
            ));

            return;
        }

        try {
            $central->statement('ALTER DATABASE "'.$current.'" RENAME TO "'.$target.'"');
        } catch (\Illuminate\Database\QueryException $e) {
            // Postgres rechaza renombrar una BD con sesiones activas (55006):
            // se detienen y se reintenta una sola vez.
            if ($e->getPrevious() instanceof \PDOException
                && str_contains((string) $e->getPrevious()->getCode(), '55006')) {
                $this->forceRenameForUnavailable($tenant, $current, $target);

                return;
            }

            throw $e;
        }

        $this->persistName($tenant, $current, $target);
    }

    private function persistName(Tenant $tenant, string $current, string $target): void
    {
        // Persiste el nuevo nombre en data['tenancy_db_name'] para que Stancl
        // reutilice esta BD en futuros arranques.
        try {
            $tenant->setInternal('db_name', $target)->save();
        } catch (\Throwable $e) {
            // El ALTER tuvo exito pero no logramos persistir el nombre: la BD
            // ya no figura bajo el nombre viejo. Reintentos manuales revisaran.
            $this->error(sprintf('  %s: la BD se renombro a %s pero fallo persistir el nombre (%s)', $tenant->slug, $target, $e->getMessage()));
        }

        $this->line(sprintf('  %s: %s -> %s', $tenant->slug, $current, $target));
    }

    private function forceRenameForUnavailable(Tenant $tenant, string $current, string $target): void
    {
        $central = DB::connection(config('tenancy.database.central_connection'));

        // Detiene las sesiones que mantienen la BD ocupada (dev: seguras) y
        // reintenta el rename una sola vez.
        $central->statement(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity
             WHERE datname = ? AND pid <> pg_backend_pid()',
            [$current]
        );
        $central->statement('ALTER DATABASE "'.$current.'" RENAME TO "'.$target.'"');

        $this->persistName($tenant, $current, $target);
    }
}