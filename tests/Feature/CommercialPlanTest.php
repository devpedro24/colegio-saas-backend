<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanCatalog;
use App\Plans\PlanPricing;
use App\Services\BulletinPreset;
use App\Services\SchoolSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommercialPlanTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as private refreshWithTransactions; }

    public function refreshDatabase(): void
    {
        if (getenv('COMMERCIAL_POSTGRES_TEST') === '1' && config('database.default') !== 'pgsql') {
            throw new \RuntimeException('The PostgreSQL integration suite cannot use a different database driver.');
        }
        if (config('database.default') !== 'pgsql') {
            $this->refreshWithTransactions();
            return;
        }
        $database = (string) config('database.connections.pgsql.database');
        if (! preg_match('/^codex_commercial_test_[a-f0-9]{12}$/D', $database)) {
            throw new \RuntimeException('PostgreSQL tests require the isolated commercial test runner.');
        }
        $this->assertSame($database, \Illuminate\Support\Facades\DB::selectOne('select current_database() as name')->name);
        // CREATE DATABASE for tenants cannot run inside a PostgreSQL transaction.
        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])->assertExitCode(0);
        \Stancl\Tenancy\DatabaseConfig::generateDatabaseNamesUsing(
            fn ($tenant) => $database.'_t_'.substr(hash('sha256', $tenant->getTenantKey()), 0, 10)
        );
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        \Illuminate\Support\Facades\DB::setDefaultConnection(config('tenancy.database.central_connection'));
        parent::tearDown();
    }

    public function test_two_plans_have_the_agreed_capabilities_and_all_custom_roles(): void
    {
        $this->assertSame(['estandar', 'premium'], array_column(PlanCatalog::defaultPlans(), 'key'));
        $standard = Plan::where('key', 'estandar')->firstOrFail();
        $premium = Plan::where('key', 'premium')->firstOrFail();
        $this->assertNull($standard->max_roles_personalizados);
        $this->assertNull($premium->max_roles_personalizados);
        foreach (['asistencia', 'preinformes', 'aula', 'boletines_personalizables'] as $feature) {
            $this->assertNotContains($feature, $standard->features);
            $this->assertContains($feature, $premium->features);
        }
        foreach (['dian', 'simat', 'carne_qr', 'pagos_pension'] as $feature) {
            $this->assertFalse(PlanCatalog::available($feature));
            $this->assertNotContains($feature, $premium->features);
        }
    }

    public function test_minimum_and_additional_sites_are_charged_once_per_school(): void
    {
        $standard = Plan::where('key', 'estandar')->firstOrFail();
        $premium = Plan::where('key', 'premium')->firstOrFail();
        $this->assertSame(45000, PlanPricing::quote($standard, ['students' => 15, 'sites' => 1])['monthly_total']);
        $this->assertSame(97500, PlanPricing::quote($standard, ['students' => 45, 'sites' => 3])['monthly_total']);
        $this->assertSame(112500, PlanPricing::quote($premium, ['students' => 45, 'sites' => 3])['monthly_total']);
        $this->assertSame(127500, PlanPricing::quote($premium, ['students' => 45, 'sites' => 4])['monthly_total']);
        $this->assertSame(1, PlanPricing::quote($premium, ['students' => 30, 'sites' => 1])['sites']);
    }

    public function test_addon_quotes_are_authoritative_and_permission_gated(): void
    {
        $this->admin();
        \Illuminate\Support\Facades\Event::fake([\App\Events\ApplicationChanged::class]);
        $contract = ['students' => 45, 'sites' => 2, 'addons' => ['asistencia']];
        $this->postJson('/api/plans/quote', ['plan' => 'estandar', 'subscription' => $contract, 'monthly_total' => 1])
            ->assertOk()->assertJsonPath('quote.monthly_total', 91500)->assertJsonPath('quote.annual_reference', 1098000)
            ->assertJsonMissingPath('quote.id');
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\ApplicationChanged::class);
        foreach ([0, 1, 29] as $invalid) {
            $contract['students'] = $invalid;
            $this->postJson('/api/plans/quote', ['plan' => 'estandar', 'subscription' => $contract])
                ->assertUnprocessable()->assertJsonValidationErrors('subscription.students');
            $this->postJson('/api/colegios', ['name' => 'Invalid', 'slug' => 'invalid', 'plan' => 'estandar',
                'rector_email' => 'invalid@capacity.test', 'subscription' => $contract])
                ->assertUnprocessable()->assertJsonValidationErrors('subscription.students');
        }
        $contract['students'] = 30;
        $contract['sites'] = 0;
        $this->postJson('/api/plans/quote', ['plan' => 'estandar', 'subscription' => $contract])
            ->assertUnprocessable()->assertJsonValidationErrors('subscription.sites');
        $contract = ['students' => 30, 'sites' => 1, 'addons' => ['dian']];
        $this->postJson('/api/plans/quote', ['plan' => 'estandar', 'subscription' => $contract])->assertUnprocessable();
    }

    public function test_school_upgrades_are_isolated_and_inherited_by_sites(): void
    {
        $standard = Plan::where('key', 'estandar')->firstOrFail();
        $school = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'price-a', 'name' => 'A', 'slug' => 'price-a',
            'plan' => 'estandar', 'commercial' => SchoolSubscription::prepare($standard, ['students' => 45, 'sites' => 2, 'addons' => ['asistencia', 'aula']])]));
        $other = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'price-b', 'name' => 'B', 'slug' => 'price-b', 'plan' => 'estandar']));
        $child = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'price-c', 'name' => 'C', 'slug' => 'price-c', 'parent_id' => $school->id, 'tipo' => 'sede', 'plan' => 'estandar']));
        $effective = SchoolSubscription::effective($child);
        $this->assertContains('asistencia', $effective->features);
        $this->assertContains('aula_colores', $effective->features);
        $this->assertSame(45, $effective->max_estudiantes);
        $this->assertSame(2, $effective->max_sedes);
        $this->assertNotContains('asistencia', SchoolSubscription::effective($other)->features);
        $this->assertNotContains('asistencia', $standard->fresh()->features);
        $this->assertSame(['asistencia', 'aula'], $school->fresh()->commercial['addons']);
        \Illuminate\Support\Facades\Event::fake([\App\Events\TenantChanged::class]);
        SchoolSubscription::notifyChanged($school);
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\TenantChanged::class, 2);
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\TenantChanged::class,
            fn ($event) => $event->tenantId === $child->id && $event->reason === 'plan');
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\TenantChanged::class,
            fn ($event) => $event->tenantId === $other->id);
    }

    public function test_presets_are_valid_and_only_style_parameters_are_variable(): void
    {
        foreach (BulletinPreset::KEYS as $key) {
            $design = BulletinPreset::design($key, '#128456', 14);
            $this->assertSame('#128456', $design['configuracion']['accent']);
            $this->assertCount(7, $design['configuracion']['canvas']['pages'][0]['blocks']);
            $this->assertSame('table', $design['configuracion']['canvas']['pages'][0]['blocks'][4]['type']);
        }
    }

    public function test_only_two_plans_are_listed_and_regular_users_cannot_quote(): void
    {
        $this->admin();
        $this->getJson('/api/plans')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/plans', ['key' => 'third', 'name' => 'Third'])->assertUnprocessable();
        $teacher = User::create(['name' => 'Teacher', 'email' => 'price-teacher@example.test', 'password' => 'Password1!',
            'role' => 'docente', 'status' => 'active']);
        app('auth')->forgetGuards();
        $this->withToken($teacher->createToken('web')->plainTextToken)
            ->postJson('/api/plans/quote', ['plan' => 'premium', 'subscription' => ['students' => 30, 'sites' => 1, 'addons' => []]])->assertForbidden();
    }

    public function test_contracted_capacity_is_enforced_before_creating_an_extra_student(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        ['tenant' => $school] = app(\App\Services\TenantProvisioner::class)->provision([
            'name' => 'Capacidad comercial', 'slug' => 'capacidad-comercial',
            'rector_email' => 'rector@capacity.test', 'plan' => 'estandar',
        ]);
        $this->assertSame(30, $school->commercial['students']);
        $school->run(function (): void {
            User::withoutEvents(function (): void {
                for ($index = 1; $index <= 30; $index++) {
                    User::create(['name' => 'Estudiante '.$index, 'email' => 'student'.$index.'@capacity.test',
                        'password' => 'Password1!', 'role' => 'estudiante', 'status' => 'active']);
                }
            });
            $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
            User::create(['name' => 'Extra', 'email' => 'extra@capacity.test',
                'password' => 'Password1!', 'role' => 'estudiante', 'status' => 'active']);
        });
    }

    public function test_storage_quota_is_shared_with_school_sites(): void
    {
        $root = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'quota-root', 'name' => 'Quota',
            'slug' => 'quota-root', 'plan' => 'estandar']));
        $child = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'quota-child', 'name' => 'Quota child',
            'slug' => 'quota-child', 'plan' => 'estandar', 'parent_id' => $root->id, 'tipo' => 'sede']));
        $service = new \App\Support\Storage\StorageService(new \App\Support\Storage\NullScanner);
        $quota = $service->quotaBytes($root);
        $this->assertSame(2 * 1024 * 1024 * 1024, $quota);
        $this->assertSame($quota, $service->quotaBytes($child));
        \App\Models\StoredFile::create(['tenant_id' => $root->id, 'disk' => 'tenant', 'path' => 'used.pdf',
            'mime' => 'application/pdf', 'size' => $quota - 10, 'checksum' => str_repeat('a', 64), 'original_name' => 'used.pdf']);
        $this->expectException(\App\Support\Storage\StorageException::class);
        $service->store(\Illuminate\Http\UploadedFile::fake()->create('extra.pdf', 1, 'application/pdf'), 'boletines', null, $child);
    }

    public function test_reseeding_preserves_configured_prices_and_capabilities(): void
    {
        $plan = Plan::where('key', 'estandar')->firstOrFail();
        $pricing = $plan->pricing;
        $pricing['student_monthly'] = 1700;
        $plan->update(['pricing' => $pricing, 'features' => [...$plan->features, 'asistencia']]);
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->assertSame(1700, $plan->fresh()->pricing['student_monthly']);
        $this->assertContains('asistencia', $plan->fresh()->features);
    }

    private function admin(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'commercial-admin@example.test', 'password' => 'Password1!',
            'role' => 'superadmin', 'status' => 'active']);
        $this->withToken($user->createToken('platform')->plainTextToken);
    }

    public function test_discount_is_validated_and_calculated_in_integer_pesos(): void
    {
        $this->admin();
        $contract = ['students' => 45, 'sites' => 2, 'addons' => ['asistencia'], 'discount_percent' => 12.5];
        $this->postJson('/api/plans/quote', ['plan' => 'estandar', 'subscription' => $contract])
            ->assertOk()->assertJsonPath('quote.subtotal', 91500)->assertJsonPath('quote.discount_total', 11438)
            ->assertJsonPath('quote.monthly_total', 80062)->assertJsonPath('quote.annual_reference', 960744);
        foreach ([-1, 101, 12.345] as $invalid) {
            $contract['discount_percent'] = $invalid;
            $this->postJson('/api/plans/quote', ['plan' => 'estandar', 'subscription' => $contract])
                ->assertUnprocessable()->assertJsonValidationErrors('subscription.discount_percent');
        }
        $contract['discount_percent'] = 100;
        $this->postJson('/api/plans/quote', ['plan' => 'estandar', 'subscription' => $contract])
            ->assertOk()->assertJsonPath('quote.monthly_total', 0);
    }

    public function test_upcoming_features_can_be_displayed_without_unlocking_them(): void
    {
        $this->admin();
        $plan = Plan::where('key', 'premium')->firstOrFail();
        $this->putJson('/api/plans/premium', ['key' => 'premium', 'name' => 'Premium',
            'features' => [...$plan->features, 'simat', 'dian']])->assertOk();
        $this->assertContains('simat', $plan->fresh()->features);
        $this->assertContains('aula_colores', $plan->fresh()->features);
        $school = Tenant::withoutEvents(fn () => Tenant::create(['id' => 'future-school', 'slug' => 'future-school',
            'name' => 'Future', 'plan' => 'premium']));
        $this->assertNotContains('simat', SchoolSubscription::effective($school)->features);
        $this->assertNotContains('dian', SchoolSubscription::effective($school)->features);
        $this->assertNotContains('aula_colores', array_column(PlanCatalog::features(), 'key'));
        $response = $this->getJson('/api/plans/offers')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('current', null)->assertJsonMissingPath('data.0.id');
        $this->assertContains('simat', $response->json('data.1.features'));
        $this->getJson('/api/plans/offers?students=60&sites=3')
            ->assertOk()->assertJsonPath('data.0.quote.monthly_total', 120000)
            ->assertJsonPath('data.1.quote.monthly_total', 150000);
        $this->getJson('/api/plans/offers?students=0')->assertUnprocessable();
        $this->getJson('/api/plans/offers?sites=0')->assertUnprocessable();
    }

    public function test_comparison_switches_to_proposal_after_one_thousand_students_or_five_sites(): void
    {
        $this->admin();
        $this->getJson('/api/plans/offers?students=1000&sites=5')->assertOk()
            ->assertJsonPath('comparison.requires_proposal', false)
            ->assertJsonPath('comparison.premium_after_students', 400);
        foreach (['students=1001&sites=1', 'students=30&sites=6'] as $query) {
            $this->getJson('/api/plans/offers?'.$query)->assertOk()
                ->assertJsonPath('comparison.requires_proposal', true);
        }
    }

    public function test_platform_saves_school_contract_and_standard_can_publish_only_presets(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->withoutMiddleware(\App\Http\Middleware\EnsureOnboardingComplete::class);
        $this->admin();
        $this->postJson('/api/colegios', ['name' => 'Colegio comercial', 'slug' => 'colegio-comercial',
            'rector_email' => 'rector@commercial.test', 'plan' => 'estandar',
            'subscription' => ['students' => 60, 'sites' => 2, 'addons' => ['asistencia'], 'discount_percent' => 10,
                'customized' => true, 'pricing_override' => ['student_monthly' => 1400, 'included_sites' => 2, 'additional_site_monthly' => 10000]]])
            ->assertCreated()->assertJsonPath('colegio.subscription.quote.monthly_total', 86400)
            ->assertJsonPath('colegio.subscription.customized', true)
            ->assertJsonPath('colegio.subscription.pricing_override.student_monthly', 1400)
            ->assertJsonPath('colegio.subscription.discount_percent', 10);
        $school = Tenant::where('slug', 'colegio-comercial')->firstOrFail();
        $token = $school->run(function (): string {
            $rector = User::where('email', 'rector@commercial.test')->firstOrFail();
            $rector->update(['must_change_password' => false]);
            return $rector->createToken('web')->plainTextToken;
        });
        $school->update(['status' => 'active']);
        app('auth')->forgetGuards();
        $base = 'http://colegio-comercial.localhost/api';
        $this->withToken($token)->getJson($base.'/subscription/offers?school=other')
            ->assertOk()->assertJsonPath('current.key', 'estandar')->assertJsonPath('current.quote.monthly_total', 86400)
            ->assertJsonPath('current.quote.annual_reference', 1036800)->assertJsonPath('current.customized', true)
            ->assertJsonPath('data.1.quote.monthly_total', 150000)
            ->assertJsonMissingPath('current.tenant_id')->assertJsonMissingPath('data.0.id');
        $this->withToken($token)->getJson($base.'/subscription/offers?students=80&sites=3')
            ->assertOk()->assertJsonPath('current.quote.monthly_total', 124200);
        $this->assertSame(60, $school->fresh()->commercial['students']);
        $this->assertSame(86400, $school->fresh()->commercial['quote']['monthly_total']);
        $this->withToken($token)->getJson($base.'/me')->assertOk()
            ->assertJsonPath('user.institution.plan.features.4', 'asistencia');
        $this->withToken($token)->postJson($base.'/boletines/plantillas', BulletinPreset::design('periodo-numerico'))->assertForbidden();
        $preset = $this->withToken($token)->postJson($base.'/boletines/predefinidos/periodo-numerico', ['accent' => '#128456', 'size' => 14])
            ->assertCreated()->assertJsonPath('data.revision_publicada', 1)->json('data.token');
        $school->run(function () use ($preset): void {
            $selected = app(\App\Services\BulletinIssueService::class)->template('periodo', $preset);
            $this->assertSame('#128456', $selected['configuracion']['accent']);
        });
    }
}
