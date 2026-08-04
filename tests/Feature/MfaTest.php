<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * MFA TOTP (RN-RG-421 / D-MFA) + secreto cifrado EN REPOSO (Fase 0 pendiente).
 *
 * Verifica que el secreto nunca se persiste en texto plano y que el login con
 * segundo factor confirmado exige el codigo en el dominio de plataforma.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Admin123!';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Super Admin',
            'email' => 'admin'.uniqid().'@plataforma.test',
            'password' => Hash::make(self::PASSWORD),
            'status' => User::STATUS_ACTIVE,
            'must_change_password' => false,
        ], $attributes));
    }

    public function test_el_secreto_totp_se_guarda_cifrado_en_bd(): void
    {
        $user = $this->createUser();

        $user->forceFill(['two_factor_secret' => 'SECRETPLAIN'])->save();

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        // Nunca texto plano: el payload cifrado de Laravel arranca con "eyJ".
        $this->assertNotSame('SECRETPLAIN', $raw);
        $this->assertStringStartsWith('eyJ', (string) $raw);

        // El modelo puede descifrarlo para el login.
        $this->assertSame('SECRETPLAIN', $user->fresh()->getTwoFactorSecret());
    }

    public function test_el_helper_resiste_secretos_legacy_en_texto_plano(): void
    {
        $user = $this->createUser();

        // Datos previos al cifrado en reposo: secreto plano en la BD.
        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => 'LEGACYPLAIN',
        ]);

        $this->assertSame('LEGACYPLAIN', $user->fresh()->getTwoFactorSecret());
    }

    public function test_login_con_mfa_confirmado_exige_codigo_valido(): void
    {
        $user = $this->createUser();

        // Secreto TOTP real y confirmado (flujo completo de setup).
        $secret = (new \App\Support\Mfa\TotpService())->generateSecret();

        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        // Sin codigo -> 422 mfa_required.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(422)->assertJson(['mfa_required' => true]);

        // Con codigo invalido -> 422.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'code' => '000000',
        ])->assertStatus(422);

        // Con codigo vigente -> token + usuario.
        $code = (new Google2FA())->getCurrentOtp($secret);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'code' => $code,
        ])->assertStatus(200)->assertJsonPath('user.email', $user->email);
    }
}