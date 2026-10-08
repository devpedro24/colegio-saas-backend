<?php

// Keep Laravel's complete English rule messages; translate the public field labels.
$messages = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
$messages['attributes'] = [
    'nombre' => 'name', 'ano_token' => 'academic year', 'desde' => 'opening date', 'hasta' => 'closing date',
    'abierta' => 'public link enabled', 'configuracion' => 'configuration',
    'configuracion.privacidad' => 'privacy notice', 'configuracion.grados' => 'grades',
    'configuracion.grados.*.cupo' => 'grade capacity', 'configuracion.documentos.*.nombre' => 'document name',
    'configuracion.documentos.*.instrucciones' => 'document description', 'configuracion.documentos.*.max_mb' => 'maximum file size',
    'configuracion.documentos.*.formatos' => 'allowed formats', 'configuracion.campos.*.nombre' => 'additional field name',
    'datos.primer_nombre' => 'first name', 'datos.primer_apellido' => 'first surname', 'datos.nacimiento' => 'date of birth',
    'datos.tipo_documento' => 'document type', 'datos.numero_documento' => 'document number',
    'grado_token' => 'grade', 'archivo' => 'file', 'consentimiento' => 'consent', 'motivo' => 'reason',
    'observacion' => 'comment', 'accion' => 'action', 'decision' => 'decision', 'app_password' => 'Google app password',
];

return $messages;
