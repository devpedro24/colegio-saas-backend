<?php

declare(strict_types=1);

namespace App\Models\Academico;

use Illuminate\Database\Eloquent\Model;

final class BoletinEmision extends Model
{
    protected $table = 'boletin_emisiones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'issued_at' => 'datetime', 'published_at' => 'datetime'];
    }
}
