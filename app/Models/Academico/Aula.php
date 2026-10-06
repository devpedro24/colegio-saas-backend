<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aula extends Model
{
    protected $table = 'aulas';
    protected $fillable = ['ano_lectivo_id', 'grupo_id', 'materia_id', 'portada_token'];

    public function grupo(): BelongsTo { return $this->belongsTo(Grupo::class); }
    public function materia(): BelongsTo { return $this->belongsTo(Materia::class); }
    public function secciones(): HasMany { return $this->hasMany(AulaSeccion::class)->orderBy('orden')->orderBy('id'); }
}
