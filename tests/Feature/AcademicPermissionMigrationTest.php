<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rbac\RbacMatrixCell;
use App\Models\Rbac\RbacPermission;
use App\Rbac\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcademicPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_recovery_permission_is_configurable_and_preserves_central_customization(): void
    {
        foreach (['docente', 'director_grupo'] as $role) {
            $this->assertContains('notas.gestionar_nivelaciones', PermissionMatrix::defaultGrantsFor($role));
            $this->assertTrue(RbacMatrixCell::where('permission_key', 'notas.gestionar_nivelaciones')
                ->where('role_key', $role)->where('type', 'configurable')->where('default_granted', true)->exists());
        }
        RbacMatrixCell::where('permission_key', 'notas.gestionar_nivelaciones')->where('role_key', 'docente')
            ->update(['default_granted' => false]);
        $migration = require database_path('migrations/2026_10_23_000003_allow_scoped_teacher_recoveries.php');
        $migration->up();
        $this->assertFalse(RbacMatrixCell::where('permission_key', 'notas.gestionar_nivelaciones')
            ->where('role_key', 'docente')->firstOrFail()->default_granted);
    }

    public function test_flexible_grading_permissions_are_registered_with_delegation_and_plan_gate(): void
    {
        $this->assertSame('preinformes', RbacPermission::where('key', 'academico.preinformes.gestionar')->value('feature_key'));
        foreach (['notas.actividades.crear', 'notas.actividades.editar', 'notas.actividades.eliminar', 'notas.planilla.configurar'] as $key) {
            $this->assertTrue(RbacPermission::where('key', $key)->exists());
            $this->assertTrue(RbacMatrixCell::where('permission_key', $key)->where('role_key', 'docente')
                ->where('type', 'configurable')->where('default_granted', true)->exists());
            $this->assertTrue(RbacMatrixCell::where('permission_key', $key)->where('role_key', 'coord_academico')
                ->where('type', 'configurable')->where('default_granted', false)->exists());
        }
    }

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

    public function test_academic_permission_catalog_uses_readable_labels_without_changing_keys(): void
    {
        $catalog = collect(PermissionMatrix::permissions())->keyBy('key');
        foreach ([
            'academico.anos.gestionar' => 'Gestionar años lectivos',
            'academico.plan_estudios.gestionar' => 'Gestionar el plan de estudios',
            'eventos.publicar_asignados' => 'Publicar eventos para grupos asignados',
        ] as $key => $action) {
            $this->assertSame('Académico', $catalog[$key]['module']);
            $this->assertSame($action, $catalog[$key]['action']);
        }

        // La migración corrige solo el texto técnico previo y no pisa una edición humana.
        DB::table('rbac_permissions')->where('key', 'academico.plan_estudios.gestionar')
            ->update(['module' => 'academico', 'action' => 'plan_estudios.gestionar']);
        DB::table('rbac_permissions')->where('key', 'academico.anos.transicionar')
            ->update(['module' => 'academico', 'action' => 'Etiqueta personalizada']);
        $migration = require database_path('migrations/2026_10_20_000004_humanize_academic_rbac_labels.php');
        $migration->up();

        $this->assertSame('Académico', RbacPermission::where('key', 'academico.plan_estudios.gestionar')->value('module'));
        $this->assertSame('Gestionar el plan de estudios', RbacPermission::where('key', 'academico.plan_estudios.gestionar')->value('action'));
        $this->assertSame('Etiqueta personalizada', RbacPermission::where('key', 'academico.anos.transicionar')->value('action'));
    }
}
