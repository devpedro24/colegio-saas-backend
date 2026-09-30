<?php

declare(strict_types=1);

return [
    // Las fechas lectivas son días civiles del colegio, no fechas UTC del servidor.
    'timezone' => env('ACADEMIC_TIMEZONE', 'America/Bogota'),
];
