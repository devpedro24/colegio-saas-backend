<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evento extends Model
{
    use SoftDeletes;

    protected $fillable = ['titulo', 'descripcion', 'fecha', 'hora_inicio', 'hora_fin', 'categoria', 'institucional', 'materia_id', 'created_by', 'estado', 'publicado_en', 'version', 'enviar_correo', 'correo_contenido'];

    protected $casts = ['institucional' => 'boolean', 'enviar_correo' => 'boolean', 'publicado_en' => 'datetime', 'version' => 'integer', 'correo_contenido' => 'encrypted:array'];

    protected $hidden = ['correo_contenido'];

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(Grupo::class, 'evento_grupo');
    }
}
