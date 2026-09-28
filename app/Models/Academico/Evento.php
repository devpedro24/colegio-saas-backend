<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evento extends Model
{
    use SoftDeletes;

    protected $fillable = ['titulo', 'descripcion', 'fecha', 'hora_inicio', 'hora_fin', 'categoria', 'institucional', 'materia_id', 'created_by'];

    protected $casts = ['institucional' => 'boolean'];

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(Grupo::class, 'evento_grupo');
    }
}
