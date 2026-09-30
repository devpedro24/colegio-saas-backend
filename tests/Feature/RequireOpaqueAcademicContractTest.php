<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireOpaqueAcademicContract;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RequireOpaqueAcademicContractTest extends TestCase
{
    public function test_missing_query_enables_opaque_contract(): void
    {
        $request = Request::create('/api/horarios', 'GET');
        $result = app(RequireOpaqueAcademicContract::class)->handle($request,
            fn (Request $resolved) => response()->json(['opaque' => $resolved->boolean('opaque')]));

        $this->assertTrue($request->boolean('opaque'));
        $this->assertTrue($result->getData(true)['opaque']);
    }

    public function test_numeric_selectors_are_rejected_even_when_nested_or_opaque_is_explicit(): void
    {
        foreach ([
            Request::create('/api/horarios', 'GET', ['grupo_id' => 5]),
            Request::create('/api/evaluacion/planillas?opaque=1', 'PUT', ['notas' => [['matricula_id' => 5]]]),
        ] as $request) {
            try {
                app(RequireOpaqueAcademicContract::class)->handle($request, fn () => response()->noContent());
                $this->fail('El contrato público no puede recibir IDs internos.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_legacy_override_requires_explicit_header_in_testing(): void
    {
        $request = Request::create('/api/horarios', 'GET', ['grupo_id' => 5]);
        $request->headers->set('X-Legacy-Academic-Ids', '1');
        $result = app(RequireOpaqueAcademicContract::class)->handle($request,
            fn (Request $resolved) => response()->json(['opaque' => $resolved->boolean('opaque')]));

        $this->assertFalse($result->getData(true)['opaque']);
    }

    public function test_opaque_cannot_be_explicitly_disabled(): void
    {
        $request = Request::create('/api/horarios?opaque=0');
        $this->expectException(ValidationException::class);
        app(RequireOpaqueAcademicContract::class)->handle($request, fn () => response()->noContent());
    }

    public function test_numeric_path_parameter_is_rejected_without_an_opaque_query(): void
    {
        $request = Request::create('/api/periodos/5');
        $route = new Route('GET', 'api/periodos/{id}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(RequireOpaqueAcademicContract::class)->handle($request, fn () => response()->noContent());
    }

    public function test_legacy_header_has_no_effect_outside_testing(): void
    {
        $environment = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            $request = Request::create('/api/horarios', 'GET', ['grupo_id' => 5]);
            $request->headers->set('X-Legacy-Academic-Ids', '1');
            $this->expectException(ValidationException::class);
            app(RequireOpaqueAcademicContract::class)->handle($request, fn () => response()->noContent());
        } finally {
            app()->detectEnvironment(fn () => $environment);
        }
    }
}
