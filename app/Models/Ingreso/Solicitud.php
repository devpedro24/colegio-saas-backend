<?php

namespace App\Models\Ingreso;

use Illuminate\Database\Eloquent\Model;

final class Solicitud extends Model
{
    protected $table = 'ingreso_solicitudes';

    protected $guarded = ['id'];

    protected $hidden = ['pin_hash', 'sesion_version'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'pin_expira' => 'datetime', 'email_verificado' => 'datetime', 'enviada_en' => 'datetime'];
    }

    public function campana()
    {
        return $this->belongsTo(Campana::class);
    }

    public function documentos()
    {
        return $this->hasMany(Documento::class);
    }
}
