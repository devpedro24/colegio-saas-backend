<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\EnsureTenantActive;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class EnsureTenantActiveTest extends TestCase
{
    public function test_suspension_permite_solo_consultas_academicas_esenciales(): void
    {
        foreach (['api/notas', 'api/notas/42', 'api/asistencia/curso', 'api/observador/7'] as $path) {
            $this->assertTrue(EnsureTenantActive::allowsSuspendedRequest(Request::create('/'.$path, 'GET')));
        }

        $this->assertFalse(EnsureTenantActive::allowsSuspendedRequest(Request::create('/api/notas/42', 'POST')));
        $this->assertFalse(EnsureTenantActive::allowsSuspendedRequest(Request::create('/api/anos-lectivos', 'GET')));
        $this->assertFalse(EnsureTenantActive::allowsSuspendedRequest(Request::create('/api/usuarios', 'GET')));
    }

    public function test_suspension_conserva_login_estado_y_logout(): void
    {
        $this->assertTrue(EnsureTenantActive::allowsSuspendedRequest(Request::create('/api/login', 'POST')));
        $this->assertTrue(EnsureTenantActive::allowsSuspendedRequest(Request::create('/api/logout', 'POST')));
        $this->assertTrue(EnsureTenantActive::allowsSuspendedRequest(Request::create('/api/me', 'GET')));
        $this->assertTrue(EnsureTenantActive::allowsSuspendedRequest(Request::create('/api/tenant-status', 'GET')));
    }
}
