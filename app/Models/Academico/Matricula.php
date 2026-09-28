<?php

namespace App\Models\Academico;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Matricula extends Model
{
    protected $fillable = ['estudiante_id', 'grupo_id', 'ano_lectivo_id', 'estado'];

    public function estudiante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'estudiante_id');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }
}
