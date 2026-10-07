<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AulaSeccion extends Model
{
    use SoftDeletes;
    protected $table = 'aula_secciones';
    protected $fillable = ['aula_id', 'periodo_id', 'preinforme_id', 'seccion_origen_id', 'titulo', 'orden', 'visible_estudiantes', 'autor_id'];
    protected $casts = ['visible_estudiantes' => 'boolean'];

    public function aula(): BelongsTo { return $this->belongsTo(Aula::class); }
    public function periodo(): BelongsTo { return $this->belongsTo(Periodo::class); }
    public function preinforme(): BelongsTo { return $this->belongsTo(Preinforme::class); }
    public function recursos(): HasMany { return $this->hasMany(AulaRecurso::class, 'seccion_id')->orderBy('orden')->orderBy('id'); }
}
