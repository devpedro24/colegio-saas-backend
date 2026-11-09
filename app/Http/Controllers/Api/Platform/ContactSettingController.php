<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\ContactNumbers;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContactSettingController extends Controller
{
    public static function contact(): array
    {
        $phone = DB::connection(config('tenancy.database.central_connection'))
            ->table('platform_contact_settings')->where('key', 'schools')->value('whatsapp');

        return ['whatsapp' => $phone, 'url' => $phone ? 'https://wa.me/'.$phone : null];
    }

    public function show()
    {
        return response()->json(self::contact());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'whatsapp' => ['present', 'nullable', 'string', 'max:32'],
            'phone_country' => ['sometimes', 'required', 'string', 'size:2'],
        ]);
        $value = $data['whatsapp'];
        if ($value && ! isset($data['phone_country']) && ! str_starts_with($value, '+')) {
            $value = '+'.$value;
        }
        $country = $data['phone_country'] ?? ContactNumbers::countryForNumber($value);
        $normalized = ContactNumbers::normalize($value, $country, 'whatsapp');
        $data['whatsapp'] = $normalized ? ltrim($normalized, '+') : null;
        $previous = self::contact();
        DB::connection(config('tenancy.database.central_connection'))->table('platform_contact_settings')
            ->updateOrInsert(['key' => 'schools'], ['whatsapp' => $data['whatsapp'], 'updated_at' => now(), 'created_at' => now()]);
        AuditLogger::platform($request->user(), 'UPDATE', 'platform.contact', null, $previous, self::contact());

        return response()->json(self::contact());
    }
}
