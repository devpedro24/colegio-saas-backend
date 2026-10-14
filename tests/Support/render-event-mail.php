<?php

// Synthetic, read-only template rendering for the isolated browser smoke test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo view('mail.event', ['english' => in_array('--en', $argv, true), 'event' => [
    'school' => 'Colegio de prueba', 'title' => 'Feria de ciencias y creatividad',
    'description' => "Te invitamos a conocer los proyectos de tu grupo.\nConsulta los detalles y los materiales en el calendario del colegio.",
    'date' => '2026-10-20', 'time' => '09:00', 'zone' => 'America/Bogota',
    'url' => 'https://colegio.example/comunicacion/eventos?evento='.str_repeat('a', 24),
]])->render();
