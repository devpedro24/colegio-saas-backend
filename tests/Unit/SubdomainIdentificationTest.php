<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\InitializeTenancyBySubdomain;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Tests\TestCase;

/**
 * Identificacion por subdominio con sedes (tenants hijos).
 *
 * El middleware propio calcula el dominio como "host completo menos el
 * dominio central", lo que permite subdominios ANIDADOS:
 * `sede.colegio-x.localhost` → `sede.colegio-x`.
 */
class SubdomainIdentificationTest extends TestCase
{
    /** @return string|NotASubdomainException */
    private function makeSubdomain(string $hostname)
    {
        $middleware = app(InitializeTenancyBySubdomain::class);

        $method = new ReflectionMethod(InitializeTenancyBySubdomain::class, 'makeSubdomain');

        return $method->invoke($middleware, $hostname);
    }

    public function test_colegio_en_dev_identifica_el_slug(): void
    {
        $this->assertSame('colegio-x', $this->makeSubdomain('colegio-x.localhost'));
    }

    public function test_sede_identifica_el_dominio_anidado(): void
    {
        $this->assertSame('sede.colegio-x', $this->makeSubdomain('sede.colegio-x.localhost'));
    }

    public function test_sede_de_varios_niveles_identifica_el_dominio_completo(): void
    {
        $this->assertSame('secundaria.norte.colegio-x', $this->makeSubdomain('secundaria.norte.colegio-x.localhost'));
    }

    public function test_hosts_centrales_no_son_subdominio(): void
    {
        $this->assertInstanceOf(NotASubdomainException::class, $this->makeSubdomain('localhost'));
        $this->assertInstanceOf(NotASubdomainException::class, $this->makeSubdomain('127.0.0.1'));
    }

    public function test_tercer_dominio_no_es_subdominio(): void
    {
        $this->assertInstanceOf(NotASubdomainException::class, $this->makeSubdomain('sede.misitio.com'));
        $this->assertInstanceOf(NotASubdomainException::class, $this->makeSubdomain('colegio-x.com'));
    }

    public function test_produccion_con_dominio_central_compuesto(): void
    {
        Config::set('tenancy.central_domains', ['app.midominio.com']);

        $this->assertSame('colegio-x', $this->makeSubdomain('colegio-x.app.midominio.com'));
        $this->assertSame('sede.colegio-x', $this->makeSubdomain('sede.colegio-x.app.midominio.com'));

        Config::set('tenancy.central_domains', ['127.0.0.1', 'localhost']);
    }
}
