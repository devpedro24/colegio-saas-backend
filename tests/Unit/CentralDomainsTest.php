<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Tenancy\CentralDomains;
use PHPUnit\Framework\TestCase;

class CentralDomainsTest extends TestCase
{
    public function test_normaliza_csv_y_elimina_hosts_repetidos(): void
    {
        $this->assertSame([
            'app.example.com',
            'localhost',
            'api.example.com',
        ], CentralDomains::fromCsv(
            ' APP.Example.com. , localhost, app.example.com, https://API.Example.com:8443/ruta, ,'
        ));
    }

    public function test_usa_defaults_seguros_si_la_lista_esta_vacia(): void
    {
        $this->assertSame(['127.0.0.1', 'localhost'], CentralDomains::fromCsv(' , '));
        $this->assertSame('localhost', CentralDomains::tenantBaseDomain(['127.0.0.1', 'localhost']));
        $this->assertSame('app.example.com', CentralDomains::tenantBaseDomain([
            'app.example.com', 'localhost',
        ]));
    }

    public function test_dominio_canonico_prefiere_app_url_y_si_no_el_primer_configurado(): void
    {
        $domains = ['app.example.com', 'localhost'];

        $this->assertSame(
            'localhost',
            CentralDomains::canonicalDomain($domains, 'http://localhost:8000'),
        );
        $this->assertSame(
            'app.example.com',
            CentralDomains::canonicalDomain($domains, 'https://fuera.example.com'),
        );
    }
}
