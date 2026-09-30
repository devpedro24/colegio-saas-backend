<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rbac\RbacMatrixCell;
use App\Models\Rbac\RbacPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_permissions_required_by_plan_studies_exist_after_migrations(): void
    {
        foreach ([
            'academico.anos.transicionar',
            'academico.periodos.transicionar',
            'academico.plan_estudios.gestionar',
        ] as $key) {
            $this->assertTrue(RbacPermission::where('key', $key)->exists(), $key);
            $this->assertTrue(RbacMatrixCell::where('permission_key', $key)
                ->where('role_key', 'rector')
                ->where('type', 'structural')
                ->where('default_granted', true)
                ->exists(), $key);
        }
    }
}
