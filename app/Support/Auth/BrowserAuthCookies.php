<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** Browser credentials are encrypted, host-only cookies; JavaScript only sees the CSRF value. */
final class BrowserAuthCookies
{
    public const PLATFORM = 'school_saas_platform';

    public const TENANT = 'school_saas_tenant';

    public const IMPERSONATION = 'school_saas_impersonation';

    public const CSRF = 'school_saas_csrf';

    public static function newCsrf(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function setSession(Response $response, Request $request, string $kind,
        string $token, int $expiresAt, string $csrf): void
    {
        self::setEncrypted($response, $request, $kind, [
            'token' => $token, 'csrf' => $csrf, 'exp' => $expiresAt,
        ], $expiresAt);
        self::setCsrf($response, $request, $csrf, $expiresAt);
    }

    public static function setImpersonation(Response $response, Request $request,
        string $token, string $tenantId, string $platformToken, int $expiresAt, string $csrf): void
    {
        self::setEncrypted($response, $request, self::IMPERSONATION, [
            'token' => $token,
            'tenant_id' => $tenantId,
            'platform_token_hash' => hash('sha256', $platformToken),
            'csrf' => $csrf,
            'exp' => $expiresAt,
        ], $expiresAt);
    }

    /** @return array<string, mixed>|null */
    public static function read(Request $request, string $name): ?array
    {
        $value = $request->cookies->get($name);
        if (! is_string($value) || $value === '' || strlen($value) > 8192) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($value), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['token'] ?? null)
            || ! is_string($data['csrf'] ?? null) || ! is_int($data['exp'] ?? null)
            || $data['token'] === '' || $data['exp'] <= time()) {
            return null;
        }

        return $data;
    }

    public static function csrf(Request $request): ?string
    {
        $context = $request->attributes->get('browser_auth');

        return is_array($context) && is_string($context['csrf'] ?? null)
            ? $context['csrf'] : null;
    }

    public static function matches(Request $request, string $name, ?string $token): bool
    {
        $cookie = self::read($request, $name);

        return $cookie !== null && is_string($token) && hash_equals($cookie['token'], $token);
    }

    public static function clearSession(Response $response, Request $request, string $kind): void
    {
        self::clear($response, $request, $kind, '/api');
        self::clear($response, $request, self::CSRF, '/');
    }

    public static function clearImpersonation(Response $response, Request $request): void
    {
        self::clear($response, $request, self::IMPERSONATION, '/api');
    }

    private static function setEncrypted(Response $response, Request $request, string $name,
        array $payload, int $expiresAt): void
    {
        $response->headers->setCookie(self::cookie($request, $name,
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), $expiresAt, '/api', true));
    }

    private static function setCsrf(Response $response, Request $request, string $csrf, int $expiresAt): void
    {
        $response->headers->setCookie(self::cookie($request, self::CSRF, $csrf, $expiresAt, '/', false));
    }

    private static function clear(Response $response, Request $request, string $name, string $path): void
    {
        $response->headers->setCookie(self::cookie($request, $name, '', time() - 3600, $path,
            $name !== self::CSRF));
    }

    private static function cookie(Request $request, string $name, string $value, int $expiresAt,
        string $path, bool $httpOnly): Cookie
    {
        // No Domain attribute: the browser never sends a school's session to a sibling school.
        $secure = $request->isSecure() || app()->environment('production');

        return Cookie::create($name, $value, $expiresAt, $path, null, $secure, $httpOnly,
            false, Cookie::SAMESITE_LAX);
    }
}
