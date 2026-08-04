<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
    protected $signature = 'purge:soft-deleted {--retention= : Dias de retencion (default: config/audit.php -> purge.retention_days)}';

    protected $description = 'Purga fisica los registros borrados logicamente tras la ventana de retencion.';

    public function handle(): int
    {
        $retentionDays = (int) ($this->option('retention')
            ?? config('audit.purge.retention_days', 30));

        $cutoff = now()->subDays($retentionDays);

        $platformUsers = User::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();
        $platformFiles = StoredFile::onlyTrashed()->where('deleted_at', '<', $cutoff)->get();

        $total = $platformUsers->count() + $platformFiles->count();
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $count = $tenant->run(function () use ($cutoff) {
                return User::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();
            });

            if ($count > 0) {
                $this->info("Colegio {$tenant->slug}: {$count} usuario(s) purgado(s).");
            }

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
            ['platform_users' => $platformUsers->count(), 'platform_files' => $platformFiles->count(), 'tenant_users' => $total - $platformUsers->count() - $platformFiles->count()],
            'Purga fisica de registros vencidos (RG-004 / RN-BR-005).',
        );

        $this->info("Purga completada: {$total} registro(s).");

        return self::SUCCESS;
    }
}