<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Storage por tenant / cuotas (RN-AC-001..006, D-STORAGE)
|--------------------------------------------------------------------------
|
| Configuracion del pipeline unico de archivos de la plataforma:
|   - Disco aislado por colegio (disco 'tenant' en filesystems.php).
|   - Cuotas de almacenamiento por PLAN (GB).
|   - Whitelist MIME (sin ejecutables).
|   - Tamano maximo por archivo.
|   - Antivirus como ADAPTADOR (interface FileScanner; NullScanner aprobador
|     hasta enchufar ClamAV).
|   - URLs firmadas de corta vida para descarga.
*/

return [

    'disk' => env('STORAGE_TENANT_DISK', 'tenant'),

    // Cuotas por plan, en GIGABYTES (D-STORAGE). El servicio las traduce a bytes.
    'quotas_gb' => [
        'esencial' => 50,
        'estandar' => 200,
        'premium' => 1024,
    ],

    // Tamano maximo por archivo (bytes). 25 MB en MVP; ajustable por env.
    'max_file_bytes' => (int) env('STORAGE_MAX_FILE_MB', 25) * 1024 * 1024,

    // ONLYOFFICE Docs autoalojado. JWT debe coincidir con JWT_SECRET del servicio.
    // Ningún documento Office se convierte a PDF en el backend del Aula.
    'aula_office_url' => env('AULA_OFFICE_URL', ''),
    'aula_office_jwt_secret' => env('AULA_OFFICE_JWT_SECRET', ''),
    // Address used by ONLYOFFICE (not the browser) to read the signed original
    // and send callbacks. It may differ from the public APP_URL in local Docker.
    'aula_office_backend_url' => env('AULA_OFFICE_BACKEND_URL', env('APP_URL', '')),

    // Whitelist MIME: documentos, imagenes, media, comprimidos con excepciones.
    // Sin ejecutables (.exe/.php/.html/.svg con script...) (RN-AC-003).
    'mime_whitelist' => [
        // Documentos
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        // Imagenes
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        // Media
        'audio/mpeg' => ['mp3'],
        'audio/ogg' => ['ogg'],
        'audio/webm' => ['webm'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
    ],

    // Vida de las URLs firmadas de descarga (minutos) (RN-AC-004).
    'signed_url_minutes' => (int) env('STORAGE_SIGNED_URL_MINUTES', 15),

    // En producción, null o un valor desconocido bloquea la carga de archivos.
    'scanner' => env('STORAGE_SCANNER', 'null'),
    'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),
    'clamav_port' => (int) env('CLAMAV_PORT', 3310),
    'clamav_timeout_seconds' => (int) env('CLAMAV_TIMEOUT_SECONDS', 10),

];
