<?php

namespace Tests\Unit;

use App\Support\Realtime\RealtimeChanges;
use PHPUnit\Framework\TestCase;

class SchoolMailRealtimeScopeTest extends TestCase
{
    public function test_requests_do_not_invalidate_enrollment_or_other_school_modules(): void
    {
        foreach (['/api/correo-institucional/solicitudes', '/api/platform/correo-solicitudes/ABCDEFGHIJKLMNOPQRSTUVWX/resolver'] as $path) {
            $this->assertSame('school-mail-requests', RealtimeChanges::resourceForPath($path));
        }
        $this->assertSame('school-mail', RealtimeChanges::resourceForPath('/api/correo-institucional'));
    }
}
