<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscalaOpcion extends Model
{
    protected $table = 'escala_opciones';

    protected $fillable = ['escala_id', 'nombre', 'orden', 'valor_equivalente', 'emoji', 'imagen_path', 'aprueba'];

    protected $casts = ['escala_id' => 'integer', 'orden' => 'integer', 'valor_equivalente' => 'decimal:2', 'aprueba' => 'boolean'];

    public function escala(): BelongsTo
    {
        return $this->belongsTo(EscalaValorativa::class, 'escala_id');
    }
}
