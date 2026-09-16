<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mfa\TotpService;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

/**
 * MFA TOTP (RN-RG-421 / D-MFA): el servicio envuelve a pragmarx/google2fa con
 * una API estable. Pure unit (sin BD): genera secretos validos y verifica
 * codigos correctos e incorrectos.
 */
class TotpServiceTest extends TestCase
{
    public function test_genera_un_secreto_base32_valido(): void
    {
        $secret = (new TotpService)->generateSecret();

        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function test_otpauth_url_contiene_secreto_issuer_y_correo(): void
    {
        $url = (new TotpService)->otpauthUrl('rector@colegio.test', 'ABCDEFGH');

        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString('secret=ABCDEFGH', $url);
        $this->assertStringContainsString('issuer=Colegio', $url);
        $this->assertStringContainsString('rector%40colegio.test', $url);
    }

    public function test_verifica_un_codigo_generado_con_el_mismo_secreto(): void
    {
        $service = new TotpService;
        $secret = $service->generateSecret();

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->assertTrue($service->verify($secret, $code));
    }

    public function test_rechaza_un_codigo_incorrecto(): void
    {
        $service = new TotpService;
        $secret = $service->generateSecret();

        $this->assertFalse($service->verify($secret, '000000'));
    }
}
