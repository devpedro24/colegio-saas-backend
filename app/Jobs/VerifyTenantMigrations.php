<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Impide activar o sembrar un tenant cuyo esquema quedó incompleto. */
final class VerifyTenantMigrations
{
    public function __construct(private readonly Tenant $tenant) {}

    public function handle(): void
    {
        $originalTenant = tenant();
        try {
            $this->tenant->run(fn () => $this->verifyCurrentDatabase());
        } finally {
            if ($originalTenant) {
                tenancy()->initialize($originalTenant);
            } else {
                tenancy()->end();
            }
        }
    }

    public function verifyCurrentDatabase(): void
    {
        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('La base de datos del tenant no tiene historial de migraciones.');
        }

        $files = glob(database_path('migrations/tenant/*.php')) ?: [];
        $expected = array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), $files);
        if ($expected === []) {
            throw new RuntimeException('No se encontraron migraciones de tenant para provisionar.');
        }

        $applied = DB::table('migrations')->pluck('migration')->all();
        $missing = array_values(array_diff($expected, $applied));
        if ($missing !== []) {
            throw new RuntimeException('El tenant tiene migraciones pendientes: '.implode(', ', $missing));
        }

        foreach (['periodos', 'jornadas', 'niveles', 'grados', 'grupos', 'bloques_horarios',
            'espacios_fisicos', 'areas', 'materias', 'materias_curriculares',
            'asignaciones_docentes', 'sesiones_horario'] as $table) {
            if (! Schema::hasColumn($table, 'ano_lectivo_id')) {
                throw new RuntimeException("Falta la columna anual {$table}.ano_lectivo_id en el tenant.");
            }
        }
        if (! Schema::hasColumn('asignaciones_docentes', 'docente_id')) {
            throw new RuntimeException('Falta la columna asignaciones_docentes.docente_id en el tenant.');
        }
    }
}
