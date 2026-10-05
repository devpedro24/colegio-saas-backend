<?php

declare(strict_types=1);

/** Configuración inicial visual para preescolar 2026; no modifica la escala global. */

use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$tenant = Tenant::where('slug', 'inmaculada')->firstOrFail();
$result = $tenant->run(function () use ($apply): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->whereNull('deleted_at')->first();
    if (! $year || $year->estado !== 'en_curso') {
        throw new RuntimeException('No se encontró Inmaculada 2026 en curso.');
    }
    $existing = EscalaValorativa::where('ano_lectivo_id', $year->id)
        ->where('nivel_educativo', 'preescolar')->first();
    if ($existing) {
        throw new RuntimeException('Preescolar ya tiene una escala; no se sobrescribe.');
    }
    $options = [
        ['nombre' => 'Excelente', 'emoji' => '😄', 'valor_equivalente' => '5.00', 'aprueba' => true],
        ['nombre' => 'Bien', 'emoji' => '🙂', 'valor_equivalente' => '4.00', 'aprueba' => true],
        ['nombre' => 'En proceso', 'emoji' => '😐', 'valor_equivalente' => '2.50', 'aprueba' => false],
        ['nombre' => 'Necesita apoyo', 'emoji' => '😟', 'valor_equivalente' => '1.50', 'aprueba' => false],
    ];
    if (! $apply) {
        return ['modo' => 'vista_previa', 'escala' => 'preescolar', 'opciones' => $options];
    }
    DB::transaction(function () use ($year, $options) {
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $year->id,
            'nivel_educativo' => 'preescolar', 'nombre' => 'Valoración integral de preescolar',
            'tipo' => 'imagenes', 'valor_min' => null, 'valor_max' => null, 'decimales' => null]);
        AuditLogger::tenant(null, 'CREATE', 'config.escala', (string) $scale->id,
            null, $scale->toArray(), 'Escala sintética configurable para auditoría 2026.');
        foreach ($options as $index => $row) {
            $option = EscalaOpcion::create(['escala_id' => $scale->id, 'orden' => $index + 1, ...$row]);
            AuditLogger::tenant(null, 'CREATE', 'escala_opcion', (string) $option->id,
                null, $option->toArray(), 'Categoría inicial; el colegio puede cambiar nombre e imagen.');
        }
    });
    return ['modo' => 'aplicado', 'escala' => 'preescolar', 'opciones' => $options];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
