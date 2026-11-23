<?php

namespace App\Models\Ingreso;

use Illuminate\Database\Eloquent\Model;

final class Notificacion extends Model
{
    protected $table = 'ingreso_notificaciones';

    protected $guarded = ['id'];

    protected $hidden = ['contenido'];

    public const REVIEW_TYPES = ['validacion', 'admision', 'grupo'];

    public static function reviewType(string $key): ?string
    {
        $type = explode(':', $key, 2)[0];

        return in_array($type, self::REVIEW_TYPES, true) ? $type : null;
    }

    public static function recoverInterrupted(): void
    {
        self::whereIn('estado', ['enviando', 'reenviando'])->whereNull('enviada_en')
            ->where('intento_en', '<=', now()->subMinutes(\App\Services\SchoolMail::REVIEW_AFTER_MINUTES))
            ->update(['estado' => 'en_revision']);
    }

    protected function casts(): array
    {
        return ['contenido' => 'encrypted', 'enviada_en' => 'datetime'];
    }
}
