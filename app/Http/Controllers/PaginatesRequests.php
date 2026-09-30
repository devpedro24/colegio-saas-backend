<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\OpaqueUrlToken;
use App\Support\AcademicOpaqueRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Métodos compartidos para respuestas paginadas consistentes en toda la API.
 *
 * Uso en cualquier controller:
 *
 *   $result = $this->paginateAcademic(Model::query()
 *       ->orderBy('nombre'), $request)
 *       ->through(fn ($item) => $this->present($item));
 *   return $this->paginatedResponse($result);
 *
 * Query params aceptados:
 *   - page     (int)  página actual, default 1
 *   - per_page (int)  items por página: 5|10|20|50|100|1000, default 20
 *
 * La respuesta incluye { data: T[], meta: { current_page, last_page, per_page, total, from, to } }.
 */
trait PaginatesRequests
{
    private const ALLOWED_PER_PAGE = [5, 10, 20, 50, 100, 1000];
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Las listas pequeñas se entregan completas. En las grandes se aplica el
     * tamaño elegido; el mismo total filtrado sirve para construir la página.
     *
     * @param array<int, string> $columns
     */
    protected function paginateAcademic(
        EloquentBuilder|QueryBuilder $query,
        Request $request,
        array $columns = ['*'],
        string $pageName = 'page',
        string $perPageName = 'per_page'
    ): LengthAwarePaginator {
        $total = (clone $query)->count();
        $smallList = $total <= self::DEFAULT_PER_PAGE;

        return $query->paginate(
            $smallList ? self::DEFAULT_PER_PAGE : $this->resolvePerPage($request, $perPageName),
            $columns,
            $pageName,
            $smallList ? 1 : $this->resolvePage($request, $pageName),
            $total
        );
    }

    protected function resolvePerPage(Request $request, string $name = 'per_page'): int
    {
        $perPage = (int) $request->query($name, $request->query('per_page', self::DEFAULT_PER_PAGE));

        return in_array($perPage, self::ALLOWED_PER_PAGE, true)
            ? $perPage
            : self::DEFAULT_PER_PAGE;
    }

    protected function resolvePage(Request $request, string $name = 'page'): int
    {
        return max(1, (int) $request->query($name, $request->query('page', 1)));
    }

    protected function paginatedResponse(LengthAwarePaginator $paginator, ?string $opaqueResource = null): JsonResponse
    {
        if ($opaqueResource !== null) {
            $paginator->through(fn ($item) => request()->boolean('opaque')
                ? AcademicOpaqueRecord::present($item, $opaqueResource)
                : [...$item->toArray(), 'url_token' => OpaqueUrlToken::for($opaqueResource, $item->getKey())]);
        }

        return response()->json([
            'data' => $paginator->items(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /** @return array<string, mixed> */
    protected function withOpaqueToken(Model $model, string $resource): array
    {
        return request()->boolean('opaque')
            ? AcademicOpaqueRecord::present($model, $resource)
            : [...$model->toArray(), 'url_token' => OpaqueUrlToken::for($resource, $model->getKey())];
    }

    /** @return array{current_page:int,last_page:int,per_page:int,total:int,from:?int,to:?int} */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
