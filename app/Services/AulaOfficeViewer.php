<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StoredFile;
use App\Models\Tenant;
use App\Support\Storage\StoredFilePublicToken;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Read-only ONLYOFFICE configuration. The stored document remains in its original format. */
final class AulaOfficeViewer
{
    private const DOCUMENT_TYPES = [
        'application/msword' => ['doc', 'word'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'word'],
        'application/vnd.ms-excel' => ['xls', 'cell'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'cell'],
        'application/vnd.ms-powerpoint' => ['ppt', 'slide'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx', 'slide'],
    ];

    public static function supports(string $mime): bool
    {
        return isset(self::DOCUMENT_TYPES[$mime]);
    }

    public function configuration(StoredFile $stored, string $title): array
    {
        [$fileType, $documentType] = self::DOCUMENT_TYPES[$stored->mime]
            ?? throw new RuntimeException('Formato de Office no compatible.');
        $server = rtrim((string) config('storage.aula_office_url'), '/');
        $backend = rtrim((string) config('storage.aula_office_backend_url'), '/');
        $secret = (string) config('storage.aula_office_jwt_secret');
        if (! filter_var($server, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($server, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! filter_var($backend, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($backend, PHP_URL_SCHEME), ['http', 'https'], true)
            || strlen($secret) < 32) {
            throw new RuntimeException('El visor Office autoalojado no está configurado.');
        }

        $config = [
            'documentType' => $documentType,
            'type' => 'desktop',
            'document' => [
                'fileType' => $fileType,
                'key' => hash('sha256', $stored->tenant_id.'|'.$stored->id.'|'.$stored->checksum),
                'title' => $title,
                // ONLYOFFICE downloads the original through a short-lived, tenant-bound URL.
                'url' => $backend.URL::temporarySignedRoute('storage.office-file', now()->addMinutes(60), [
                    'file' => StoredFilePublicToken::for($stored),
                    'school' => Tenant::findOrFail($stored->tenant_id)->slug,
                ], false),
                'permissions' => ['edit' => false, 'comment' => false, 'download' => false,
                    'print' => true, 'copy' => true],
            ],
            'editorConfig' => ['mode' => 'view', 'lang' => 'es',
                'coEditing' => ['mode' => 'strict', 'change' => false],
                'callbackUrl' => $backend.route('aula.office-callback', [], false)],
        ];
        $config['token'] = $this->jwt($config, $secret);

        return ['script_url' => $server.'/web-apps/apps/api/documents/api.js', 'config' => $config];
    }

    private function jwt(array $payload, string $secret): string
    {
        $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode(
            $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $message = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($payload);

        return $message.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $secret, true)), '+/', '-_'), '=');
    }

    public function validCallbackToken(string $token): bool
    {
        $secret = (string) config('storage.aula_office_jwt_secret');
        $parts = explode('.', $token);
        if (strlen($secret) < 32 || count($parts) !== 3) return false;
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') return false;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0].'.'.$parts[1], $secret, true)), '+/', '-_'), '=');
        return hash_equals($expected, $parts[2]);
    }
}
