<?php

declare(strict_types=1);

namespace App\Models\Academico;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AsignacionDocente extends Model
{
    use SoftDeletes;

    protected $table = 'asignaciones_docentes';

    protected $fillable = ['ano_lectivo_id', 'grupo_id', 'materia_id', 'docente_id'];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }
}
