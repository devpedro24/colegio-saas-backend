<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPublicKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_api_uses_public_key_and_never_returns_database_id(): void
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'plan-key@example.test',
            'password' => 'Password1!', 'role' => 'superadmin',
            'status' => User::STATUS_ACTIVE,
        ]);
        $token = $user->createToken('platform')->plainTextToken;
        $plan = Plan::create(['key' => 'plan-seguro', 'name' => 'Plan Seguro']);

        $this->withToken($token)->getJson('/api/plans')->assertOk()
            ->assertJsonPath('data.0.key', 'plan-seguro')
            ->assertJsonMissing(['id' => $plan->id]);
        $this->getJson('/api/plans/'.$plan->id)->assertNotFound();
        $this->getJson('/api/plans/'.$plan->key)->assertOk()
            ->assertJsonPath('plan.key', 'plan-seguro')
            ->assertJsonMissing(['id' => $plan->id]);

        $this->putJson('/api/plans/'.$plan->key, [
            'key' => $plan->key, 'name' => 'Actualizado', 'features' => [],
        ])->assertOk()->assertJsonPath('plan.name', 'Actualizado')
            ->assertJsonMissing(['id' => $plan->id]);
    }
}
