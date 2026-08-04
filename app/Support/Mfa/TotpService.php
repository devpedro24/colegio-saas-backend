<?php

declare(strict_types=1);

namespace App\Support\Mfa;

use PragmaRX\Google2FA\Google2FA;

/**
 * Servicio TOTP (RFC 6238) para el MFA de la plataforma y de los colegios
 * (RN-RG-421 / D-MFA). Envuelve pragmarx/google2fa (framework-agnostic) para
 * que controladores y Wire dependan de una API estable y minima.
 *
 * Estandar: HMAC-SHA1, paso de 30s, 6 digitos, secreto en base32.
 */
final class TotpService
{
    private const ISSUER = 'Colegio SaaS';

    /**
     * Tolerancia de desfase de reloj: se aceptan codigos de +/- 1 ventana
     * (30s) respecto al momento actual.
     */
    private const WINDOW = 1;

    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Genera un nuevo secreto TOTP en base32 (sin confirmar).
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * URL otpauth:// para pintar el QR en la app autenticadora.
     * Formato: otpauth://totp/{issuer}:{email}?secret=...&issuer=...
     */
    public function otpauthUrl(string $email, string $secret): string
    {
        $label = rawurlencode(self::ISSUER) . ':' . rawurlencode($email);

        $params = http_build_query([
            'secret' => $secret,
            'issuer' => self::ISSUER,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);

        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Verifica un codigo de 6 digitos contra el secreto del usuario.
     */
    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }
}
