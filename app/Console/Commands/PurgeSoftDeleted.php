<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Academico\AnoLectivo;
use App\Models\Academico\BloqueHorario;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\EspacioFisico;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Jornada;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\ModeloPedagogico;
use App\Models\Academico\Nivel;
use App\Models\Academico\Periodo;
use App\Models\Academico\Sede;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Purga fisica de registros soft-deleted vencida la ventana de retencion
 * (RG-004, RN-BR-005). El dorsal de Fase 0: borra usuarios y archivos de la
 * plataforma y de cada colegio, y deja evidencia en el log de auditoria.
 *
 *   php artisan purge:soft-deleted                  # todo (retencion configurada)
 *   php artisan purge:soft-deleted --retention=30  # dias de retencion
 */
class PurgeSoftDeleted extends Command
{
    protected $signature = 'purge:soft-deleted {--retention= : Dias de retencion (default: config/retention.php)}';

    protected $description = 'Purga fisica los registros borrados logicamente tras la ventana de retencion.';

    public function handle(): int
    {
        $retentionDays = (int) ($this->option('retention')
            ?? config('retention.soft_deleted_days', 30));

        if ($retentionDays < 1) {
            $this->error('La retencion debe ser de al menos un dia.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($retentionDays);

        $platformUsers = User::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();
        $platformFiles = StoredFile::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();

        $total = $platformUsers->count() + $platformFiles->count();
        $tenants = Tenant::all();

        $tenantCounts = [];
        $tenantErrors = [];
        foreach ($tenants as $tenant) {
            try {
                $counts = $tenant->run(fn (): array => $this->purgeTenant($cutoff));
            } catch (Throwable $exception) {
                $tenantErrors[(string) $tenant->id] = $exception->getMessage();
                $this->warn("Colegio {$tenant->slug}: no se pudo ejecutar la purga.");

                continue;
            } finally {
                // Tenant::run() only restores the previous context when its
                // callback completes. A connection/bootstrap failure must not
                // leave the command (or the following tenants) on that DB.
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }

            $count = array_sum($counts);
            if ($count > 0) {
                $this->info("Colegio {$tenant->slug}: {$count} registro(s) purgado(s).");
            }

            $tenantCounts[(string) $tenant->id] = $counts;
            $total += $count;
        }

        foreach ($platformUsers as $user) {
            $user->forceDelete();
        }

        foreach ($platformFiles as $file) {
            Storage::disk($file->disk)->delete($file->path);
            $file->forceDelete();
        }

        AuditLogger::platform(
            null,
            'PURGE',
            'soft_deleted',
            null,
            null,
            [
                'platform_users' => $platformUsers->count(),
                'platform_files' => $platformFiles->count(),
                'tenants' => $tenantCounts,
                'tenant_errors' => $tenantErrors,
            ],
            'Purga fisica de registros vencidos (RG-004 / RN-BR-005).',
        );

        if ($tenantErrors !== []) {
            $this->error(sprintf(
                'Purga incompleta: %d tenant(s) fallaron; %d registro(s) fueron purgados en los alcances disponibles.',
                count($tenantErrors),
                $total,
            ));

            return self::FAILURE;
        }

        $this->info("Purga completada: {$total} registro(s).");

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function purgeTenant($cutoff): array
    {
        // Dependencias primero; evita violaciones FK incluso donde no exista
        // cascade y permite purgar configuracion/academia, no solo usuarios.
        $models = [
            BloqueHorario::class,
            Grupo::class,
            EspacioFisico::class,
            Jornada::class,
            Grado::class,
            Nivel::class,
            EscalaValorativa::class,
            MetodoAprobacion::class,
            ModeloPedagogico::class,
            Periodo::class,
            AnoLectivo::class,
            Sede::class,
            User::class,
        ];

        $counts = [];
        foreach ($models as $modelClass) {
            $model = new $modelClass;
            if (! Schema::hasTable($model->getTable())) {
                continue;
            }

            $counts[$model->getTable()] = $modelClass::onlyTrashed()
                ->where('deleted_at', '<', $cutoff)
                ->forceDelete();
        }

        return $counts;
    }
}
