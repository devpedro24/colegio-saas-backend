<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Ciclo de vida del usuario (D-USER-FSM): transiciones manuales validadas y
 * borrado logico (RG-004). El modelo User es compartido (central + tenant).
 */
class UserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $status): User
    {
        return User::create([
            'name' => 'Prueba',
            'email' => 'user'.uniqid().'@test.test',
            'password' => 'secret',
            'status' => $status,
        ]);
    }

    public function test_pending_puede_activarse(): void
    {
        $user = $this->makeUser(User::STATUS_PENDING);

        $user->transitionTo(User::STATUS_ACTIVE);

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_active_no_puede_volver_a_pending(): void
    {
        $user = $this->makeUser(User::STATUS_ACTIVE);

        $this->expectException(InvalidArgumentException::class);

        $user->transitionTo(User::STATUS_PENDING);
    }

    public function test_suspendido_reactiva_y_viceversa(): void
    {
        $user = $this->makeUser(User::STATUS_ACTIVE);
        $user->transitionTo(User::STATUS_SUSPENDED);

        $this->assertSame(User::STATUS_SUSPENDED, $user->fresh()->status);

        $user->transitionTo(User::STATUS_ACTIVE);

        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_estados_terminales_no_aceptan_mas_transiciones(): void
    {
        $user = $this->makeUser(User::STATUS_ACTIVE);
        $user->transitionTo(User::STATUS_DELETED);

        $this->assertFalse($user->canTransitionTo(User::STATUS_ACTIVE));
    }

    public function test_soft_delete_oculta_al_usuario_pero_conserva_la_fila(): void
    {
        $user = $this->makeUser(User::STATUS_ACTIVE);

        $user->delete();

        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }

    public function test_login_de_usuario_inactivo_es_rechazado(): void
    {
        $user = $this->makeUser(User::STATUS_INACTIVE);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret',
        ]);

        $response->assertStatus(422);
    }
}