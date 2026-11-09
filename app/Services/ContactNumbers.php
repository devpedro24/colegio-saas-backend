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

    public static function normalize(?string $value, string $country, string $field): ?string
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
            $field => 'Ingresa un celular válido para +'.$code.' de '.implode(', ', $lengths).' dígitos, sin incluir el indicativo.',
        ]);
    }

    public static function countryForNumber(?string $value, string $fallback = 'CO'): string
    {
        try {
            $phones = PhoneNumberUtil::getInstance();

            return $phones->getRegionCodeForNumber($phones->parse($value ?? '', $fallback)) ?: $fallback;
        } catch (NumberParseException) {
            return $fallback;
        }
    }
}
