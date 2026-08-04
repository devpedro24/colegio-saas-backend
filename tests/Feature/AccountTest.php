<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Ajustes de cuenta del usuario autenticado (perfil, email, contraseña y
 * desactivación). Se prueban contra el dominio de PLATAFORMA (BD central).
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Admin123!';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSuperadmin(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Super Admin',
            'email' => 'admin'.uniqid().'@plataforma.test',
            'password' => Hash::make(self::PASSWORD),
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
        ], $attributes));
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken('platform')->plainTextToken;
    }

    public function test_actualiza_perfil_con_nombre_y_telefono(): void
    {
        $user = $this->createSuperadmin();
        $token = $this->createTokenFor($user);

        $this->withToken($token)->putJson('/api/account/profile', [
            'name' => 'Nuevo Nombre',
            'phone' => '+57 300 123 4567',
        ])->assertStatus(200)
            ->assertJsonPath('user.name', 'Nuevo Nombre')
            ->assertJsonPath('user.phone', '+57 300 123 4567');

        $this->assertSame('Nuevo Nombre', $user->fresh()->name);
        $this->assertSame('+57 300 123 4567', $user->fresh()->phone);
    }

    public function test_cambiar_email_exige_contrasena_y_rechaza_duplicados(): void
    {
        $user = $this->createSuperadmin();
        $other = $this->createSuperadmin(['email' => 'ocupado@plataforma.test']);
        $token = $this->createTokenFor($user);

        // Sin contraseña -> 422 en password.
        $this->withToken($token)->postJson('/api/account/email', [
            'email' => 'nuevo@plataforma.test',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Contraseña incorrecta -> 422.
        $this->withToken($token)->postJson('/api/account/email', [
            'email' => 'nuevo@plataforma.test',
            'password' => 'ClaveErrada!9',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Email ya en uso por otro usuario -> 422.
        $this->withToken($token)->postJson('/api/account/email', [
            'email' => 'ocupado@plataforma.test',
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // Correcto -> cambia y desverifica.
        $this->withToken($token)->postJson('/api/account/email', [
            'email' => 'nuevo@plataforma.test',
            'password' => self::PASSWORD,
        ])->assertStatus(200)->assertJsonPath('user.email', 'nuevo@plataforma.test');

        $this->assertSame('nuevo@plataforma.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_cambiar_contrasena_verifica_actual_y_politica(): void
    {
        $user = $this->createSuperadmin();
        $token = $this->createTokenFor($user);

        // Actual incorrecta -> 422.
        $this->withToken($token)->postJson('/api/account/password', [
            'current_password' => 'MalActual!8',
            'new_password' => 'NuevaClave!2026',
            'new_password_confirmation' => 'NuevaClave!2026',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        // Debil (sin símbolo) -> 422.
        $this->withToken($token)->postJson('/api/account/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'sololetras2026',
            'new_password_confirmation' => 'sololetras2026',
        ])->assertStatus(422);

        // Correcto -> cambia y marca que ya no debe cambiarla.
        $this->withToken($token)->postJson('/api/account/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'NuevaClave!2026',
            'new_password_confirmation' => 'NuevaClave!2026',
        ])->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NuevaClave!2026', $fresh->password));
        $this->assertFalse((bool) $fresh->must_change_password);
    }

    public function test_desactivar_cuenta_bloquea_el_login_y_revoca_tokens(): void
    {
        $user = $this->createSuperadmin();
        $token = $this->createTokenFor($user);

        $this->withToken($token)->postJson('/api/account/deactivate', [
            'password' => 'MalActual!8',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->withToken($token)->postJson('/api/account/deactivate', [
            'password' => self::PASSWORD,
        ])->assertStatus(200);

        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);
        $this->assertCount(0, $user->fresh()->tokens);

        // Ya no puede iniciar sesion.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'El usuario no esta activo.');
    }
}