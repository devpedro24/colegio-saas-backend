<?php

declare(strict_types=1);

/**
 * Completa solamente el primer grupo de Prejardín a Cuarto hasta su cupo.
 * Cuentas ficticias verosímiles para auditar matrículas; no son alumnos reales.
 * Conserva todas las matrículas existentes. Por defecto solo muestra el plan.
 *
 * php scripts/cargar_inmaculada_2026_auditoria_matriculas.php [--apply]
 */

use App\Models\Academico\Matricula;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Realtime\RealtimeChanges;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);
$tenant = Tenant::where('slug', 'inmaculada')->firstOrFail();
if ($tenant->tipo !== Tenant::TIPO_COLEGIO) {
    throw new RuntimeException('El tenant no es el colegio principal.');
}

$firstNames = [
    'Sofía Valentina', 'Juan Sebastián', 'María Fernanda', 'Samuel Andrés', 'Isabella',
    'Matías', 'Luciana', 'Daniel Felipe', 'Antonella', 'Emiliano', 'Gabriela',
    'Nicolás', 'Mariana', 'Thiago', 'Sara', 'Jerónimo', 'Valeria', 'Martín',
    'Salomé', 'Tomás', 'Camila', 'Santiago', 'Victoria', 'Alejandro', 'Ana Lucía',
    'Simón', 'Juliana', 'David', 'Laura', 'Joaquín', 'Catalina', 'Esteban',
];
$surnames = [
    'Álvarez', 'Andrade', 'Arango', 'Arias', 'Barrios', 'Bedoya', 'Bermúdez',
    'Cabrera', 'Caicedo', 'Cárdenas', 'Castillo', 'Castro', 'Córdoba', 'Díaz',
    'Duarte', 'Escobar', 'Espinosa', 'Fernández', 'Flórez', 'García', 'Gómez',
    'González', 'Gutiérrez', 'Herrera', 'Jiménez', 'López', 'Martínez', 'Medina',
    'Mendoza', 'Morales', 'Muñoz', 'Navarro', 'Ocampo', 'Ortiz', 'Pérez',
    'Ramírez', 'Restrepo', 'Ríos', 'Rodríguez', 'Rojas', 'Salazar', 'Sánchez',
    'Suárez', 'Torres', 'Vargas', 'Velásquez', 'Zapata',
];
$targetGrades = ['Prejardín', 'Jardín', 'Transición', 'Primero', 'Segundo', 'Tercero', 'Cuarto'];

$result = $tenant->run(function () use ($apply, $firstNames, $surnames, $targetGrades): array {
    $year = DB::table('anos_lectivos')->where('nombre', '2026')->whereNull('deleted_at')->first();
    if (! $year || $year->estado !== 'en_curso') {
        throw new RuntimeException('Inmaculada 2026 no está en curso.');
    }

    $groups = DB::table('grupos as g')
        ->join('grados as d', 'd.id', '=', 'g.grado_id')
        ->where('g.ano_lectivo_id', $year->id)
        ->whereIn('d.nombre', $targetGrades)
        ->whereNull('g.deleted_at')->whereNull('d.deleted_at')
        ->orderBy('g.id')
        ->get(['g.id', 'g.nombre', 'g.cupo_maximo', 'd.nombre as grado']);
    $selected = collect($targetGrades)->map(function (string $grade) use ($groups) {
        $group = $groups->firstWhere('grado', $grade);
        if (! $group || ! $group->cupo_maximo || $group->cupo_maximo > 40) {
            throw new RuntimeException("Falta el primer grupo o un cupo válido para {$grade}.");
        }
        return $group;
    });
    $preview = $selected->map(function ($group) use ($year) {
        $existing = Matricula::where('ano_lectivo_id', $year->id)->where('grupo_id', $group->id)->where('estado', 'activa')->count();
        if ($existing > $group->cupo_maximo) {
            throw new RuntimeException("El grupo {$group->nombre} supera su cupo; no se modificó.");
        }
        return ['grado' => $group->grado, 'grupo' => $group->nombre, 'existentes' => $existing,
            'cupo' => (int) $group->cupo_maximo, 'nuevas' => (int) $group->cupo_maximo - $existing];
    })->all();
    if (! $apply) {
        return ['modo' => 'vista_previa', 'grupos' => $preview];
    }

    // Agrupar los avisos de cada matrícula: una carga no debe producir cientos
    // de invalidaciones de caché ni consultas repetidas en todos los navegadores.
    $changes = app(RealtimeChanges::class);
    $changes->begin();
    try {
        DB::transaction(function () use ($selected, $year, $firstNames, $surnames) {
        $global = 0;
        foreach ($selected as $group) {
            $active = Matricula::where('ano_lectivo_id', $year->id)->where('grupo_id', $group->id)
                ->where('estado', 'activa')->count();
            for ($slot = $active + 1; $slot <= $group->cupo_maximo; $slot++) {
                $global++;
                $name = $firstNames[($global * 7) % count($firstNames)].' '
                    .$surnames[($global * 11) % count($surnames)].' '
                    .$surnames[($global * 17 + 3) % count($surnames)];
                $email = sprintf('estudiante.2026.%s.%03d@inmaculada.example.invalid', strtolower($group->nombre), $slot);
                if (User::where('email', $email)->exists()) {
                    throw new RuntimeException("La cuenta {$email} ya existe sin matrícula esperada; se canceló toda la transacción.");
                }
                $student = User::create(['name' => $name, 'email' => $email,
                    'password' => Str::random(64), 'role' => 'estudiante', 'status' => 'active',
                    'must_change_password' => true]);
                $student->assignRole('estudiante');
                $enrollment = Matricula::create(['estudiante_id' => $student->id,
                    'grupo_id' => $group->id, 'ano_lectivo_id' => $year->id, 'estado' => 'activa']);
                AuditLogger::tenant(null, 'CREATE', 'matricula', (string) $enrollment->id,
                    null, $enrollment->toArray(), 'Carga sintética local para auditoría académica 2026; fecha de registro real.');
            }
        }
        });
    } finally {
        $changes->flush();
    }

    return ['modo' => 'aplicado', 'grupos' => $preview];
});

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
