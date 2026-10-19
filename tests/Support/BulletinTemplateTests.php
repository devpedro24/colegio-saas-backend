<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Plan;
use App\Models\User;
use App\Services\BulletinTemplateDesign;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

trait BulletinTemplateTests
{
    private function templateApi(User $user): string
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        app('auth')->forgetGuards();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($user->createToken('test-bulletin-template')->plainTextToken);

        return 'http://localhost/api/boletines/plantillas?opaque=1';
    }

    private function templateInput(string $scope = 'periodo'): array
    {
        return ['nombre' => 'Diseño institucional', 'ambito' => $scope,
            'configuracion' => BulletinTemplateDesign::base($scope)];
    }

    public function test_bulletin_template_base_is_visible_on_basic_plan_but_editing_requires_feature_and_permission(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.ver', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.gestionar', 'web'));
        Plan::updateOrCreate(['key' => 'esencial'], ['name' => 'Esencial', 'features' => ['academico']]);
        $this->school->update(['plan' => 'esencial']);
        $api = $this->templateApi($this->rector);
        $this->getJson($api)->assertOk()->assertJsonPath('data.colegio', 'Colegio')
            ->assertJsonPath('data.puede_editar', false)
            ->assertJsonPath('data.base.periodo.sections.0.key', 'student');
        $this->postJson($api, $this->templateInput())->assertForbidden();
        $this->templateApi($this->teacher);
        $this->getJson($api)->assertForbidden();
    }

    public function test_bulletin_template_live_design_publishes_immutable_revisions_without_emitting_a_report(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.ver', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.gestionar', 'web'));
        Plan::updateOrCreate(['key' => 'estandar'], ['name' => 'Estándar',
            'features' => ['academico', 'boletines_personalizables']]);
        $this->school->update(['plan' => 'estandar']);
        $api = $this->templateApi($this->rector);
        $this->getJson($api)->assertOk()->assertJsonPath('data.puede_editar', true);
        $invalid = $this->templateInput();
        $invalid['configuracion']['sections'][2]['key'] = 'student';
        $this->postJson($api, $invalid)->assertUnprocessable();
        $invalid = $this->templateInput();
        $invalid['configuracion']['heading'] = '<script>malicioso</script>';
        $this->postJson($api, $invalid)->assertUnprocessable();
        $created = $this->postJson($api, $this->templateInput())
            ->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.revision_publicada', null)->json('data');
        $path = 'http://localhost/api/boletines/plantillas/'.$created['token'].'?opaque=1';
        $first = $this->templateInput();
        $first['configuracion']['accent'] = '#812A60';
        $this->postJson('http://localhost/api/boletines/plantillas/'.$created['token'].'/publicar?opaque=1',
            [...$first, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.revision_publicada', 1);
        $changed = $this->templateInput();
        $changed['configuracion']['accent'] = '#167A70';
        $this->putJson($path, [...$changed, 'version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('boletin_plantilla_revisiones', ['revision' => 1]);
        $this->assertSame('#812A60', json_decode(DB::table('boletin_plantilla_revisiones')
            ->where('revision', 1)->value('configuracion'), true)['accent']);
        $this->putJson($path, [...$changed, 'version' => 2])->assertConflict();
        $this->postJson('http://localhost/api/boletines/plantillas/'.$created['token'].'/publicar?opaque=1',
            [...$changed, 'version' => 3])->assertOk()->assertJsonPath('data.revision_publicada', 2);
        $this->assertDatabaseCount('boletin_plantilla_revisiones', 2);
        $this->assertDatabaseCount('boletin_plantillas', 1);
        $this->getJson($path)->assertOk()->assertJsonPath('data.configuracion.accent', '#167A70');
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'boletin_plantilla', 'accion' => 'PUBLISH']);
        $this->assertDatabaseMissing('audit_logs', ['recurso' => 'boletin_emitido']);
        $this->school->update(['plan' => 'esencial']);
        Plan::updateOrCreate(['key' => 'esencial'], ['name' => 'Esencial', 'features' => ['academico']]);
        $this->getJson($api)->assertOk()->assertJsonPath('data.puede_editar', false);
        $this->putJson($path, [...$changed, 'version' => 4])->assertForbidden();
    }
}
