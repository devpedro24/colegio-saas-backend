<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Métodos compartidos para respuestas paginadas consistentes en toda la API.
 *
 * Uso en cualquier controller:
 *
 *   $result = Model::query()
 *       ->orderBy('nombre')
 *       ->paginate($this->resolvePerPage($request))
 *       ->through(fn ($item) => $this->present($item));
 *   return $this->paginatedResponse($result);
 *
 * Query params aceptados:
 *   - page     (int)  página actual, default 1
 *   - per_page (int)  items por página: 5|10|15|20|25|50, default 5, max 50
 *
 * La respuesta incluye { data: T[], meta: { current_page, last_page, per_page, total } }.
 */
trait PaginatesRequests
{
    private const ALLOWED_PER_PAGE = [5, 10, 15, 20, 25, 50];

    private const DEFAULT_PER_PAGE = 5;

    protected function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return in_array($perPage, self::ALLOWED_PER_PAGE, true)
            ? $perPage
            : self::DEFAULT_PER_PAGE;
    }

    protected function paginatedResponse(LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
