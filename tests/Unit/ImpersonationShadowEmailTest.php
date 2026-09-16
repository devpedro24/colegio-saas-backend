<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class ImpersonationShadowEmailTest extends TestCase
{
    public function test_namespace_shadow_es_reservado_sin_importar_mayusculas(): void
    {
        $this->assertTrue(User::isImpersonationShadowEmail('superadmin@plataforma.local'));
        $this->assertTrue(User::isImpersonationShadowEmail('SUPERADMIN+42@PLATAFORMA.LOCAL'));
        $this->assertTrue(User::isImpersonationShadowEmail('superadmin+legacy@plataforma.local'));
        $this->assertFalse(User::isImpersonationShadowEmail('superadmin@colegio.test'));
        $this->assertFalse(User::isImpersonationShadowEmail('rector@plataforma.local'));
    }
}
