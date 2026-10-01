<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RecuperacionAcademica extends Model
{
    protected $table = 'recuperaciones_academicas';

    protected $fillable = [
        'ano_lectivo_id', 'matricula_id', 'asignacion_id', 'periodo_id', 'alcance',
        'tipo', 'estado', 'politica', 'valor_original_exacto', 'nota_recuperacion', 'nota_manual',
        'valor_efectivo_exacto', 'plan_mejoramiento', 'motivo', 'created_by',
        'resolved_by', 'cancelled_by', 'version',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'nota_recuperacion' => 'decimal:8', 'nota_manual' => 'decimal:8'];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(AsignacionDocente::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }
}
