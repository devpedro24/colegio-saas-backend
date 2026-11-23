<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeZone;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

final class ContactNumbers
{
    public static function countries(): array
    {
        $phones = PhoneNumberUtil::getInstance();
        $countries = [];
        foreach ($phones->getSupportedRegions() as $country) {
            $metadata = $phones->getMetadataForRegion($country);
            $mobile = $metadata->getMobile();
            if ($mobile->getNationalNumberPattern() === '') {
                continue;
            }
            $example = $phones->getExampleNumberForType($country, PhoneNumberType::MOBILE);
            $countries[] = [
                'country' => $country,
                'calling_code' => (string) $phones->getCountryCodeForRegion($country),
                'lengths' => $mobile->getPossibleLength() ?: $metadata->getGeneralDesc()->getPossibleLength(),
                'pattern' => $mobile->getNationalNumberPattern(),
                'leading_digits' => $metadata->getLeadingDigits(),
                'example' => $example ? $phones->getNationalSignificantNumber($example) : '',
                'timezones' => DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country),
            ];
        }

        return $countries;
    }

    public static function countryForTimezone(?string $timezone): string
    {
        foreach (PhoneNumberUtil::getInstance()->getSupportedRegions() as $country) {
            if (in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country), true)) {
                return $country;
            }
        }

        return 'CO';
    }

    public static function normalize(?string $value, string $country, string $field, bool $partial = false): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $phones = PhoneNumberUtil::getInstance();
        if (! in_array($country, $phones->getSupportedRegions(), true)) {
            throw ValidationException::withMessages([$field => 'Selecciona un país válido para el celular.']);
        }
        $value = trim($value);
        $digits = preg_replace('/[\s().-]/', '', $value);
        $code = (string) $phones->getCountryCodeForRegion($country);
        $national = str_starts_with($digits, '+'.$code) ? substr($digits, strlen($code) + 1) : $digits;
        $metadata = $phones->getMetadataForRegion($country);
        $lengths = $metadata->getMobile()->getPossibleLength() ?: $metadata->getGeneralDesc()->getPossibleLength();
        // Autosaved drafts may contain an unfinished number, never an oversized one.
        if ($partial && str_starts_with($digits, '+'.$code) && ctype_digit($national) && strlen($national) <= max($lengths)) {
            return '+'.$code.$national;
        }
        try {
            $number = $phones->parse($value, $country);
            if (ctype_digit($national) && in_array(strlen($national), $lengths, true)
                && ! $number->hasExtension() && $phones->isValidNumberForRegion($number, $country)
                && in_array($phones->getNumberType($number), [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
                return $phones->format($number, PhoneNumberFormat::E164);
            }
        } catch (NumberParseException) {
            // Return the same field error for malformed and unsupported numbers.
        }

        throw ValidationException::withMessages([
            $field => 'Ingresa un número celular válido.',
        ]);
    }

    public static function countryForNumber(?string $value, string $fallback = 'CO'): string
    {
        try {
            $phones = PhoneNumberUtil::getInstance();

            $number = $phones->parse($value ?? '', $fallback);

            return $phones->getRegionCodeForNumber($number) ?: ($phones->getRegionCodeForCountryCode($number->getCountryCode()) ?: $fallback);
        } catch (NumberParseException) {
            $digits = preg_replace('/[\s().-]/', '', $value ?? '');
            if (str_starts_with($digits, '+')) {
                for ($length = 1; $length <= 3; $length++) {
                    $region = $phones->getRegionCodeForCountryCode((int) substr($digits, 1, $length));
                    if ($region !== 'ZZ') {
                        return $region;
                    }
                }
            }

            return $fallback;
        }
    }
}
