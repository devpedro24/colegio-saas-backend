<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CorreoConfiguracion extends Model
{
    protected $table = 'correo_configuracion';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['app_password', 'revision', 'ultima_autorizacion'];

    protected static function booted(): void
    {
        static::creating(function (self $settings) {
            $settings->revision = Str::random(32);
        });
    }

    protected function casts(): array
    {
        return ['app_password' => 'encrypted', 'verificado_en' => 'datetime', 'desconectado_en' => 'datetime'];
    }
}
