<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Make public selectors the only academic API contract outside legacy tests. */
final class RequireOpaqueAcademicContract
{
    public function handle(Request $request, Closure $next): Response
    {
        $opaque = $request->boolean('opaque');
        if (! $opaque && app()->environment('testing') && $request->header('X-Legacy-Academic-Ids') === '1') {
            return $next($request);
        }
        if (! $opaque && $request->exists('opaque')) {
            throw ValidationException::withMessages(['opaque' => 'La API requiere identificadores públicos.']);
        }
        if (! $opaque) {
            $request->query->set('opaque', '1');
        }
        $this->rejectPrivateIdentifiers($request->all());
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if (is_scalar($value) && ctype_digit((string) $value)) {
                abort(404);
            }
        }

        return $next($request);
    }

    private function rejectPrivateIdentifiers(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $field = $path === '' ? (string) $key : $path.'.'.$key;
            if ($key === 'id' || str_ends_with((string) $key, '_id') || str_ends_with((string) $key, '_ids')) {
                throw ValidationException::withMessages([$field => 'Usa el selector público correspondiente.']);
            }
            if (is_array($value)) {
                $this->rejectPrivateIdentifiers($value, $field);
            }
        }
    }
}
