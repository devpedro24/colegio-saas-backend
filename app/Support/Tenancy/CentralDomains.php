<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/** Normaliza la configuracion de hosts usada por routing y tenancy. */
final class CentralDomains
{
    /** @return list<string> */
    public static function fromCsv(?string $csv): array
    {
        $domains = array_values(array_unique(array_filter(array_map(
            self::normalizeHost(...),
            explode(',', (string) $csv),
        ))));

        return $domains !== [] ? $domains : ['127.0.0.1', 'localhost'];
    }

    public static function normalizeHost(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        $candidate = str_contains($value, '://') ? $value : 'http://'.$value;
        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = trim(strtolower($host), '.');

        return $host !== '' ? $host : null;
    }

    /** @param list<string> $centralDomains */
    public static function tenantBaseDomain(array $centralDomains, ?string $configured = null): string
    {
        $explicit = self::normalizeHost($configured);
        if ($explicit !== null) {
            return $explicit;
        }

        foreach ($centralDomains as $domain) {
            if ($domain !== 'localhost' && filter_var($domain, FILTER_VALIDATE_IP) === false) {
                return $domain;
            }
        }

        return in_array('localhost', $centralDomains, true)
            ? 'localhost'
            : ($centralDomains[0] ?? 'localhost');
    }

    /** @param list<string> $centralDomains */
    public static function canonicalDomain(array $centralDomains, ?string $appUrl): string
    {
        $appHost = self::normalizeHost($appUrl);

        if ($appHost !== null && in_array($appHost, $centralDomains, true)) {
            return $appHost;
        }

        return $centralDomains[0] ?? 'localhost';
    }
}
