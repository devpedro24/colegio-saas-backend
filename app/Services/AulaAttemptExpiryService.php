<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Academico\AulaIntento;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Expire unfinished exams with server time; expiration never assigns a zero. */
final class AulaAttemptExpiryService
{
    public function expireDue(): int
    {
        $expired = 0;
        AulaIntento::where('estado', 'en_curso')->whereNotNull('vence_at')
            ->where('vence_at', '<=', now('UTC'))->chunkById(100, function ($attempts) use (&$expired): void {
                foreach ($attempts as $attempt) {
                    DB::transaction(function () use ($attempt, &$expired): void {
                        $current = AulaIntento::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                        if ($current->estado !== 'en_curso' || ! $current->vence_at
                            || $current->vence_at->gt(now('UTC'))) {
                            return;
                        }
                        $before = $current->toArray();
                        $current->update(['estado' => 'tiempo_agotado', 'finalizado_at' => now('UTC'),
                            'version' => $current->version + 1]);
                        AuditLogger::tenant(null, 'UPDATE', 'aula_intento', (string) $current->id, $before,
                            $current->toArray(), 'Vencimiento automático por reloj del servidor.');
                        $expired++;
                    });
                }
            });

        return $expired;
    }
}
