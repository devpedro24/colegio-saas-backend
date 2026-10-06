<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Academico\AnoLectivoController;
use App\Http\Controllers\Api\Academico\AsistenciaController;
use App\Http\Controllers\Api\Academico\AsistenciaCorreccionController;
use App\Http\Controllers\Api\Academico\AulaController;
use App\Http\Controllers\Api\Academico\AulaArchivoController;
use App\Http\Controllers\Api\Academico\AulaCuestionarioController;
use App\Http\Controllers\Api\Academico\AcademicOptionsController;
use App\Http\Controllers\Api\Academico\BloqueHorarioController;
use App\Http\Controllers\Api\Academico\DatosInstitucionalesController;
use App\Http\Controllers\Api\Academico\EscalaValorativaController;
use App\Http\Controllers\Api\Academico\EscalaOpcionController;
use App\Http\Controllers\Api\Academico\EspacioFisicoController;
use App\Http\Controllers\Api\Academico\EvaluacionController;
use App\Http\Controllers\Api\Academico\EventoController;
use App\Http\Controllers\Api\Academico\GradoController;
use App\Http\Controllers\Api\Academico\GrupoController;
use App\Http\Controllers\Api\Academico\HorarioController;
use App\Http\Controllers\Api\Academico\JornadaController;
use App\Http\Controllers\Api\Academico\MetodoAprobacionController;
use App\Http\Controllers\Api\Academico\ModeloPedagogicoController;
use App\Http\Controllers\Api\Academico\NivelController;
use App\Http\Controllers\Api\Academico\PeriodoController;
use App\Http\Controllers\Api\Academico\PlanEstudiosController;
use App\Http\Controllers\Api\Academico\PreparacionEvaluacionController;
use App\Http\Controllers\Api\Academico\PromocionAcademicaController;
use App\Http\Controllers\Api\Academico\SedeController;
use App\Http\Controllers\Api\Academico\SieeController;
use App\Http\Controllers\Api\InstitutionContextController;
use App\Http\Controllers\Api\Platform\StorageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\ResolveOpaqueSieeYear;
use App\Http\Middleware\ResolveAcademicRouteIdentifier;
use App\Http\Middleware\ResolveAcademicInputIdentifiers;
use App\Http\Middleware\RequireOpaqueAcademicContract;
use App\Services\ConfigurationGate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas ACADEMICAS del colegio (Bloque A / Fase 1) — COMPARTIDAS
|--------------------------------------------------------------------------
|
| Estas definiciones se incluyen (require) desde DOS lugares para que apunten
| a los MISMOS controladores del colegio:
|
|   1. routes/tenant.php  -> grupo de SUBDOMINIO (<slug>.<dominio>), como
|      siempre lo han consumido los colegios reales.
|   2. routes/api.php     -> grupo CENTRAL con sesión de suplantación en cookie
|      HttpOnly, por donde el superadmin alcanza el colegio sin subdominio.
|
| Se asume que el grupo incluyente YA aplica:
|   - `auth:sanctum` (identidad del usuario del colegio o del usuario sombra),
|   - el prefijo `api`,
|   - y la inicialización de tenancy (por subdominio o sesión de suplantación).
| Aqui solo van los controles de autorizacion por permiso (`can:`).
|
*/

Route::middleware(RequireOpaqueAcademicContract::class)->group(function () {
Route::get('/catalogos-academicos', AcademicOptionsController::class);
Route::get('/config/escalas/{escala}/opciones/{opcion}/imagen', [EscalaOpcionController::class, 'image'])->middleware([
    ResolveAcademicRouteIdentifier::class.':escala,escala-valorativa',
    ResolveAcademicRouteIdentifier::class.':opcion,escala-opcion',
]);
Route::get('/preinformes', [\App\Http\Controllers\Api\Academico\PreinformeController::class, 'index']);
Route::put('/preinformes/{periodo}', [\App\Http\Controllers\Api\Academico\PreinformeController::class, 'save']);
Route::put('/evaluacion/planillas/{asignacion}/{periodo}/actividades', [\App\Http\Controllers\Api\Academico\PlanillaActividadesController::class, 'save'])->middleware([
    ResolveAcademicRouteIdentifier::class.':asignacion,asignacion-docente',
    ResolveAcademicRouteIdentifier::class.':periodo,periodo',
]);
Route::prefix('aula')->controller(AulaController::class)->group(function () {
    Route::get('/catalogo', 'catalogo');
    Route::post('/', 'crear');
    Route::get('/{aulaToken}', 'ver');
    Route::post('/{aulaToken}/secciones', 'crearSeccion');
    Route::put('/secciones/{token}', 'editarSeccion');
    Route::post('/secciones/{sectionToken}/recursos', 'crearRecurso');
    Route::get('/recursos/{token}', 'verRecurso');
    Route::put('/recursos/{token}', 'editarRecurso');
    Route::post('/recursos/{token}/entregas', 'entregar');
    Route::get('/recursos/{resourceToken}/entregas', 'entregas');
    Route::put('/entregas/{token}/calificar', 'calificar');
});
Route::prefix('aula')->controller(AulaCuestionarioController::class)->group(function () {
    Route::put('/recursos/{token}/preguntas', 'guardarPreguntas');
    Route::get('/recursos/{token}/mi-intento', 'miIntento');
    Route::post('/recursos/{token}/intentos', 'iniciar');
    Route::get('/recursos/{resourceToken}/intentos', 'intentos');
    Route::get('/intentos/{token}', 'verIntento');
    Route::put('/intentos/{token}/respuestas', 'guardarRespuestas');
    Route::put('/intentos/{token}/pagina', 'cambiarPagina');
    Route::post('/intentos/{token}/finalizar', 'finalizar');
    Route::post('/intentos/{token}/incidentes', 'incidente');
    Route::post('/intentos/{token}/reactivar', 'reactivar');
    Route::put('/intentos/{token}/calificar', 'calificar');
});
Route::prefix('aula')->controller(AulaArchivoController::class)->group(function () {
    Route::post('/{token}/portada', 'subirPortada')->middleware('throttle:school-uploads');
    Route::get('/{token}/portada', 'descargarPortada');
    Route::post('/recursos/{token}/adjuntos', 'subirRecurso')->middleware('throttle:school-uploads');
    Route::post('/entregas/{token}/adjuntos', 'subirEntrega')->middleware('throttle:school-uploads');
    Route::get('/adjuntos/{token}/imagen', 'verImagen');
    Route::get('/adjuntos/{token}', 'descargar');
});

// Años lectivos y periodos: permiso 'academico.anos.gestionar'.
Route::middleware('can:academico.anos.gestionar')->group(function () {
    Route::get('/anos-lectivos', [AnoLectivoController::class, 'index']);
    Route::post('/anos-lectivos', [AnoLectivoController::class, 'store']);
    Route::post('/anos-lectivos/{id}/duplicar', [AnoLectivoController::class, 'duplicar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::post('/anos-lectivos/{id}/copiar-configuracion', [AnoLectivoController::class, 'copiarConfiguracion'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::get('/anos-lectivos/{id}/estado-copia', [AnoLectivoController::class, 'estadoCopia'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::get('/anos-lectivos/{id}/resumen-duplicacion', [AnoLectivoController::class, 'resumenDuplicacion'])
        ->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::get('/anos-lectivos/{id}', [AnoLectivoController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::put('/anos-lectivos/{id}', [AnoLectivoController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::delete('/anos-lectivos/{id}', [AnoLectivoController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');

    // Periodos: index/store anidados bajo el año; mutaciones por id de periodo.
    Route::get('/anos-lectivos/{ano}/periodos', [PeriodoController::class, 'index'])->middleware(ResolveAcademicRouteIdentifier::class.':ano,ano-lectivo');
    Route::post('/anos-lectivos/{ano}/periodos', [PeriodoController::class, 'store'])->middleware(ResolveAcademicRouteIdentifier::class.':ano,ano-lectivo');
    Route::put('/periodos/{id}', [PeriodoController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,periodo');
    Route::delete('/periodos/{id}', [PeriodoController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,periodo');
});

// La gestión del calendario puede delegarse; sus cambios de estado son del rector.
Route::middleware('can:academico.anos.transicionar')->group(function () {
    Route::get('/anos-lectivos/{ano}/promociones', [PromocionAcademicaController::class, 'index'])->middleware(ResolveAcademicRouteIdentifier::class.':ano,ano-lectivo');
    Route::put('/anos-lectivos/{ano}/promociones/politica', [PromocionAcademicaController::class, 'policy'])->middleware(ResolveAcademicRouteIdentifier::class.':ano,ano-lectivo');
    Route::put('/anos-lectivos/{ano}/promociones/{matricula}', [PromocionAcademicaController::class, 'approve'])->middleware([
        ResolveAcademicRouteIdentifier::class.':ano,ano-lectivo', ResolveAcademicRouteIdentifier::class.':matricula,matricula',
    ]);
    Route::post('/anos-lectivos/{id}/iniciar', [AnoLectivoController::class, 'iniciar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::get('/anos-lectivos/{id}/revision-cierre', [AnoLectivoController::class, 'revisionCierre'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::post('/anos-lectivos/{id}/cerrar', [AnoLectivoController::class, 'cerrar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
    Route::post('/anos-lectivos/{id}/reabrir', [AnoLectivoController::class, 'reabrir'])->middleware(ResolveAcademicRouteIdentifier::class.':id,ano-lectivo');
});
Route::middleware('can:academico.periodos.transicionar')->group(function () {
    Route::post('/periodos/{id}/abrir', [PeriodoController::class, 'abrir'])->middleware(ResolveAcademicRouteIdentifier::class.':id,periodo');
    Route::post('/periodos/{id}/cerrar', [PeriodoController::class, 'cerrar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,periodo');
    Route::post('/periodos/{id}/reabrir', [PeriodoController::class, 'reabrir'])->middleware(ResolveAcademicRouteIdentifier::class.':id,periodo');
});

// Configuración del colegio: permiso 'academico.configurar'.
Route::middleware('can:academico.configurar')->prefix('config')->group(function () {
    // Estado del gate de operabilidad (D-CONFIG-MIN): que bloques estan completos.
    Route::get('/progreso', fn () => response()->json(ConfigurationGate::estado()));

    Route::get('/datos-institucionales', [DatosInstitucionalesController::class, 'show']);
    Route::put('/datos-institucionales', [DatosInstitucionalesController::class, 'update']);
    Route::get('/zona-horaria', [DatosInstitucionalesController::class, 'zonaHoraria']);
    Route::put('/zona-horaria', [DatosInstitucionalesController::class, 'guardarZonaHoraria']);

    Route::get('/escalas', [EscalaValorativaController::class, 'index']);
    Route::post('/escalas', [EscalaValorativaController::class, 'store']);
    Route::put('/escalas/{id}', [EscalaValorativaController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,escala-valorativa');
    Route::delete('/escalas/{id}', [EscalaValorativaController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,escala-valorativa');
    Route::put('/escalas/{escala}/opciones', [EscalaOpcionController::class, 'save'])->middleware(ResolveAcademicRouteIdentifier::class.':escala,escala-valorativa');
    Route::post('/escalas/{escala}/opciones/{opcion}/imagen', [EscalaOpcionController::class, 'upload'])->middleware([
        ResolveAcademicRouteIdentifier::class.':escala,escala-valorativa',
        ResolveAcademicRouteIdentifier::class.':opcion,escala-opcion', 'throttle:school-uploads',
    ]);

    Route::get('/metodos-aprobacion', [MetodoAprobacionController::class, 'index']);
    Route::post('/metodos-aprobacion', [MetodoAprobacionController::class, 'store']);
    Route::put('/metodos-aprobacion/{id}', [MetodoAprobacionController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,metodo-aprobacion');
    Route::delete('/metodos-aprobacion/{id}', [MetodoAprobacionController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,metodo-aprobacion');

    Route::get('/modelos-pedagogicos', [ModeloPedagogicoController::class, 'index']);
    Route::post('/modelos-pedagogicos', [ModeloPedagogicoController::class, 'store']);
    Route::put('/modelos-pedagogicos/{id}', [ModeloPedagogicoController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,modelo-pedagogico');
    Route::delete('/modelos-pedagogicos/{id}', [ModeloPedagogicoController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,modelo-pedagogico');
});

// Jerarquía organizacional (Bloque B / Fase 1): Sede→Jornada→Nivel→Grado→
// Grupo + bloques horarios + espacios físicos. Permiso 'academico.estructura.gestionar'.
Route::middleware(['can:academico.estructura.gestionar', ResolveAcademicInputIdentifiers::class])->prefix('estructura')->group(function () {
    // Sedes
    Route::get('/sedes', [SedeController::class, 'index']);
    Route::post('/sedes', [SedeController::class, 'store']);
    Route::get('/sedes/{id}', [SedeController::class, 'show']);
    Route::put('/sedes/{id}', [SedeController::class, 'update']);
    Route::delete('/sedes/{id}', [SedeController::class, 'destroy']);
    Route::post('/sedes/{id}/heredar', [SedeController::class, 'heredar']);

    // Jornadas (pertenecen a una sede)
    Route::get('/jornadas', [JornadaController::class, 'index']);
    Route::post('/jornadas', [JornadaController::class, 'store']);
    Route::get('/jornadas/{id}', [JornadaController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,jornada');
    Route::put('/jornadas/{id}', [JornadaController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,jornada');
    Route::delete('/jornadas/{id}', [JornadaController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,jornada');

    // Niveles educativos
    Route::get('/niveles', [NivelController::class, 'index']);
    Route::post('/niveles', [NivelController::class, 'store']);
    Route::get('/niveles/{id}', [NivelController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,nivel');
    Route::put('/niveles/{id}', [NivelController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,nivel');
    Route::delete('/niveles/{id}', [NivelController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,nivel');

    // Grados (pertenecen a un nivel)
    Route::get('/grados', [GradoController::class, 'index']);
    Route::post('/grados', [GradoController::class, 'store']);
    Route::get('/grados/{id}', [GradoController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,grado');
    Route::put('/grados/{id}', [GradoController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,grado');
    Route::delete('/grados/{id}', [GradoController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,grado');

    // Grupos (grado + año lectivo + jornada)
    Route::get('/grupos', [GrupoController::class, 'index']);
    Route::post('/grupos', [GrupoController::class, 'store']);
    Route::get('/grupos/{id}', [GrupoController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,grupo');
    Route::put('/grupos/{id}', [GrupoController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,grupo');
    Route::delete('/grupos/{id}', [GrupoController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,grupo');

    // Bloques horarios
    Route::get('/bloques-horarios', [BloqueHorarioController::class, 'index']);
    Route::post('/bloques-horarios', [BloqueHorarioController::class, 'store']);
    Route::get('/bloques-horarios/{id}', [BloqueHorarioController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,bloque-horario');
    Route::put('/bloques-horarios/{id}', [BloqueHorarioController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,bloque-horario');
    Route::delete('/bloques-horarios/{id}', [BloqueHorarioController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,bloque-horario');

    // Espacios físicos
    Route::get('/espacios-fisicos', [EspacioFisicoController::class, 'index']);
    Route::post('/espacios-fisicos', [EspacioFisicoController::class, 'store']);
    Route::get('/espacios-fisicos/{id}', [EspacioFisicoController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,espacio-fisico');
    Route::put('/espacios-fisicos/{id}', [EspacioFisicoController::class, 'update'])->middleware(ResolveAcademicRouteIdentifier::class.':id,espacio-fisico');
    Route::delete('/espacios-fisicos/{id}', [EspacioFisicoController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,espacio-fisico');
});

// Plan de estudios (Bloque C): áreas y materias. Las asignaciones y horarios
// se suman sobre estas entidades en el siguiente incremento.
Route::middleware(['can:academico.plan_estudios.gestionar', ResolveAcademicInputIdentifiers::class])->prefix('plan-estudios')->group(function () {
    Route::get('/areas', [PlanEstudiosController::class, 'areas']);
    Route::post('/areas', [PlanEstudiosController::class, 'storeArea']);
    Route::put('/areas/{id}', [PlanEstudiosController::class, 'updateArea'])->middleware(ResolveAcademicRouteIdentifier::class.':id,area');
    Route::delete('/areas/{id}', [PlanEstudiosController::class, 'destroyArea'])->middleware(ResolveAcademicRouteIdentifier::class.':id,area');

    Route::get('/materias', [PlanEstudiosController::class, 'materias']);
    Route::post('/materias', [PlanEstudiosController::class, 'storeMateria']);
    Route::put('/materias/{id}', [PlanEstudiosController::class, 'updateMateria'])->middleware(ResolveAcademicRouteIdentifier::class.':id,materia');
    Route::delete('/materias/{id}', [PlanEstudiosController::class, 'destroyMateria'])->middleware(ResolveAcademicRouteIdentifier::class.':id,materia');
});

// Carga genérica institucional: requiere permiso y límite por usuario/colegio.
// Los adjuntos de un módulo usan su propia autorización de recurso.
Route::post('/archivos', [StorageController::class, 'store'])
    ->middleware(['can:archivos.subir', 'throttle:school-uploads']);

Route::get('/horarios', [HorarioController::class, 'index'])->middleware(ResolveAcademicInputIdentifiers::class);
Route::get('/asistencias', [AsistenciaController::class, 'index']);
Route::get('/asistencias/planilla', [AsistenciaController::class, 'sheet']);
Route::put('/asistencias', [AsistenciaController::class, 'save']);
Route::get('/asistencias/politica/{ano}', [AsistenciaController::class, 'policy'])
    ->middleware(ResolveAcademicRouteIdentifier::class.':ano,ano-lectivo');
Route::put('/asistencias/politica/{ano}', [AsistenciaController::class, 'savePolicy'])
    ->middleware(ResolveAcademicRouteIdentifier::class.':ano,ano-lectivo');
Route::get('/asistencias/mis-faltas', [AsistenciaCorreccionController::class, 'ownAbsences']);
Route::get('/asistencias/solicitudes', [AsistenciaCorreccionController::class, 'index']);
Route::post('/asistencias/solicitudes', [AsistenciaCorreccionController::class, 'store']);
Route::put('/asistencias/solicitudes/{token}/revision-docente', [AsistenciaCorreccionController::class, 'review']);
Route::put('/asistencias/solicitudes/{token}/resolucion', [AsistenciaCorreccionController::class, 'resolve']);
Route::middleware(ResolveAcademicInputIdentifiers::class)->prefix('evaluacion')->controller(EvaluacionController::class)->group(function () {
    Route::get('/catalogo', 'catalogo');
    Route::post('/matriculas', 'matricular');
    Route::get('/planillas/{asignacion}/{periodo}', 'planilla')->middleware([
        ResolveAcademicRouteIdentifier::class.':asignacion,asignacion-docente',
        ResolveAcademicRouteIdentifier::class.':periodo,periodo',
    ]);
    Route::put('/planillas/{asignacion}/{periodo}', 'notas')->middleware([
        ResolveAcademicRouteIdentifier::class.':asignacion,asignacion-docente',
        ResolveAcademicRouteIdentifier::class.':periodo,periodo',
    ]);
    Route::post('/componentes', 'componente');
    Route::put('/componentes/{id}', 'componente')->middleware(ResolveAcademicRouteIdentifier::class.':id,componente-evaluacion');
    Route::post('/actividades', 'actividad');
    Route::put('/actividades/{id}', 'actividad')->middleware(ResolveAcademicRouteIdentifier::class.':id,actividad-evaluacion');
    Route::get('/boletines/{id}', 'boletin')->middleware(ResolveAcademicRouteIdentifier::class.':id,matricula');
});
Route::middleware(ResolveAcademicInputIdentifiers::class)->prefix('evaluacion/recuperaciones')
    ->controller(\App\Http\Controllers\Api\Academico\RecuperacionAcademicaController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::put('/{id}', 'update')->middleware(ResolveAcademicRouteIdentifier::class.':id,recuperacion-academica');
        Route::post('/{id}/anular', 'anular')->middleware(ResolveAcademicRouteIdentifier::class.':id,recuperacion-academica');
    });
Route::middleware(['can:academico.configurar', ResolveOpaqueSieeYear::class])->group(function () {
    Route::get('/siee/{id}', [SieeController::class, 'show']);
    Route::get('/siee/{id}/curriculo', [SieeController::class, 'curriculoIndex']);
    Route::put('/siee/{id}', [SieeController::class, 'update']);
    Route::put('/siee/{id}/curriculo', [SieeController::class, 'curriculo']);
    Route::put('/siee/{id}/curriculo/masivo', [SieeController::class, 'curriculoMasivo']);
    Route::get('/siee/{id}/preparacion', [PreparacionEvaluacionController::class, 'show']);
    Route::put('/siee/{id}/preparacion', [PreparacionEvaluacionController::class, 'save']);
    Route::post('/siee/{id}/preparacion/aplicar', [PreparacionEvaluacionController::class, 'apply']);
});
Route::middleware(ResolveAcademicInputIdentifiers::class)->group(function () {
    Route::get('/eventos/catalogo', [EventoController::class, 'catalogo']);
    Route::put('/eventos/configuracion', [EventoController::class, 'configurar']);
    Route::get('/eventos', [EventoController::class, 'index']);
    Route::post('/eventos', [EventoController::class, 'guardar']);
    Route::get('/eventos/{id}', [EventoController::class, 'show'])->middleware(ResolveAcademicRouteIdentifier::class.':id,evento');
    Route::put('/eventos/{id}', [EventoController::class, 'guardar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,evento');
    Route::delete('/eventos/{id}', [EventoController::class, 'destroy'])->middleware(ResolveAcademicRouteIdentifier::class.':id,evento');
    Route::post('/eventos/{id}/archivos', [EventoController::class, 'archivo'])->middleware([
        ResolveAcademicRouteIdentifier::class.':id,evento', 'throttle:school-uploads',
    ]);
});
Route::middleware(['can:academico.plan_estudios.gestionar', ResolveAcademicInputIdentifiers::class])->group(function () {
    Route::post('/asignaciones', [HorarioController::class, 'asignar']);
    Route::post('/asignaciones/grupo', [HorarioController::class, 'asignarGrupo']);
    Route::put('/asignaciones/{id}', [HorarioController::class, 'editarAsignacion'])->middleware(ResolveAcademicRouteIdentifier::class.':id,asignacion-docente');
    Route::delete('/asignaciones/{id}', [HorarioController::class, 'desasignar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,asignacion-docente');
    Route::post('/horarios', [HorarioController::class, 'guardar']);
    Route::put('/horarios/{id}', [HorarioController::class, 'guardar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,sesion-horario');
    Route::delete('/horarios/{id}', [HorarioController::class, 'eliminar'])->middleware(ResolveAcademicRouteIdentifier::class.':id,sesion-horario');
});
});

// Usuarios del colegio (permiso 'usuarios.gestionar'): el alta/edicion
// puede apuntar a una sede (tenant hijo) y se escribe en su propia BD.
// Disponible tanto por subdominio como por header X-Tenant (suplantacion).
Route::middleware('can:usuarios.gestionar')->prefix('usuarios')->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::put('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    Route::get('/{id}/temporal-password', [UserController::class, 'temporalPassword']);
    Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);
});

Route::get('/institution-context', InstitutionContextController::class);
