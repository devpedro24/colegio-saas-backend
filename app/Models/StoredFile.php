<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Rbac\CentralModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Registro de un archivo subido por un colegio (pipeline unico, RN-AC-001..006).
 *
 * Metadato central (cuotas por plan + descarga firmada se resuelven fuera del
 * contexto del colegio); los BYTES viven en el disco 'tenant' bajo
 * <tenant_id>/<carpeta>/<uuid>.<ext>. Soft-delete (RG-004): la fila persiste
 * hasta la ventana de retencion, cuando la purga fisica borra objeto+fila.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $checksum
 * @property string $original_name
 * @property ?string $uploaded_by_email
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class StoredFile extends CentralModel
{
    use SoftDeletes;

    protected $table = 'stored_files';

    protected $fillable = [
        'tenant_id',
        'disk',
        'path',
        'mime',
        'size',
        'checksum',
        'original_name',
        'uploaded_by_email',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    /**
     * Cuota en bytes consumida actualmente por el colegio (excl. borrados).
     */
    public static function usedBytesFor(string $tenantId): int
    {
        return (int) self::query()
            ->where('tenant_id', $tenantId)
            ->sum('size');
    }
}
