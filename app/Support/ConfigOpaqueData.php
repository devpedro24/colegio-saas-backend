<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Academico\AnoLectivo;
use App\Services\AcademicYearSelection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Explicit public contract for the three academic parameter catalogs. */
final class ConfigOpaqueData
{
    public static function year(Request $request): AnoLectivo
    {
        $request->validate([
            'ano_lectivo_token' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
            'ano_lectivo_id' => ['prohibited'],
        ]);

        return AcademicYearSelection::fromRequest($request);
    }

    /** @param list<string> $fields @return array<string,mixed> */
    public static function present(Model $model, string $resource, array $fields): array
    {
        return [
            'url_token' => OpaqueUrlToken::for($resource, $model->getKey()),
            'ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $model->getAttribute('ano_lectivo_id')),
            ...$model->only($fields),
        ];
    }
}
