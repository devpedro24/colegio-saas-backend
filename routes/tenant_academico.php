<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Academico\AnoLectivoController;
use App\Http\Controllers\Api\Academico\BloqueHorarioController;
use App\Http\Controllers\Api\Academico\DatosInstitucionalesController;
use App\Http\Controllers\Api\Academico\EscalaValorativaController;
use App\Http\Controllers\Api\Academico\EspacioFisicoController;
use App\Http\Controllers\Api\Academico\GradoController;
use App\Http\Controllers\Api\Academico\GrupoController;
use App\Http\Controllers\Api\Academico\JornadaController;
use App\Http\Controllers\Api\Academico\MetodoAprobacionController;
use App\Http\Controllers\Api\Academico\ModeloPedagogicoController;
use App\Http\Controllers\Api\Academico\NivelController;
use App\Http\Controllers\Api\Academico\PeriodoController;
use App\Http\Controllers\Api\Academico\SedeController;
use App\Http\Controllers\Api\Platform\StorageController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
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
|   2. routes/api.php     -> grupo CENTRAL header-resuelto (localhost + header
|      'X-Tenant: <colegio.id>'), por donde el superadmin suplantando alcanza
|      el mismo colegio SIN subdominio.
|
| Se asume que el grupo incluyente YA aplica:
|   - `auth:sanctum` (identidad del usuario del colegio o del usuario sombra),
|   - el prefijo `api`,
|   - y la inicializacion de tenancy (por subdominio o por header X-Tenant).
| Aqui solo van los controles de autorizacion por permiso (`can:`).
|
*/

// Años lectivos y periodos: permiso 'academico.anos.gestionar'.
Route::middleware('can:academico.anos.gestionar')->group(function () {
    Route::get('/anos-lectivos', [AnoLectivoController::class, 'index']);
    Route::post('/anos-lectivos', [AnoLectivoController::class, 'store']);
    Route::get('/anos-lectivos/{id}', [AnoLectivoController::class, 'show']);
    Route::put('/anos-lectivos/{id}', [AnoLectivoController::class, 'update']);
    Route::post('/anos-lectivos/{id}/iniciar', [AnoLectivoController::class, 'iniciar']);
    Route::post('/anos-lectivos/{id}/cerrar', [AnoLectivoController::class, 'cerrar']);

    // Periodos: index/store anidados bajo el año; mutaciones por id de periodo.
    Route::get('/anos-lectivos/{ano}/periodos', [PeriodoController::class, 'index']);
    Route::post('/anos-lectivos/{ano}/periodos', [PeriodoController::class, 'store']);
    Route::put('/periodos/{id}', [PeriodoController::class, 'update']);
    Route::delete('/periodos/{id}', [PeriodoController::class, 'destroy']);
    Route::post('/periodos/{id}/abrir', [PeriodoController::class, 'abrir']);
    Route::post('/periodos/{id}/cerrar', [PeriodoController::class, 'cerrar']);
});

// Configuración del colegio: permiso 'academico.configurar'.
Route::middleware('can:academico.configurar')->prefix('config')->group(function () {
    // Estado del gate de operabilidad (D-CONFIG-MIN): que bloques estan completos.
    Route::get('/progreso', fn () => response()->json(ConfigurationGate::estado()));

    Route::get('/datos-institucionales', [DatosInstitucionalesController::class, 'show']);
    Route::put('/datos-institucionales', [DatosInstitucionalesController::class, 'update']);

    Route::get('/escalas', [EscalaValorativaController::class, 'index']);
    Route::post('/escalas', [EscalaValorativaController::class, 'store']);
    Route::put('/escalas/{id}', [EscalaValorativaController::class, 'update']);
    Route::delete('/escalas/{id}', [EscalaValorativaController::class, 'destroy']);

    Route::get('/metodos-aprobacion', [MetodoAprobacionController::class, 'index']);
    Route::post('/metodos-aprobacion', [MetodoAprobacionController::class, 'store']);
    Route::put('/metodos-aprobacion/{id}', [MetodoAprobacionController::class, 'update']);
    Route::delete('/metodos-aprobacion/{id}', [MetodoAprobacionController::class, 'destroy']);

    Route::get('/modelos-pedagogicos', [ModeloPedagogicoController::class, 'index']);
    Route::post('/modelos-pedagogicos', [ModeloPedagogicoController::class, 'store']);
    Route::put('/modelos-pedagogicos/{id}', [ModeloPedagogicoController::class, 'update']);
    Route::delete('/modelos-pedagogicos/{id}', [ModeloPedagogicoController::class, 'destroy']);
});

// Jerarquía organizacional (Bloque B / Fase 1): Sede→Jornada→Nivel→Grado→
// Grupo + bloques horarios + espacios físicos. Permiso 'academico.estructura.gestionar'.
Route::middleware('can:academico.estructura.gestionar')->prefix('estructura')->group(function () {
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
    Route::get('/jornadas/{id}', [JornadaController::class, 'show']);
    Route::put('/jornadas/{id}', [JornadaController::class, 'update']);
    Route::delete('/jornadas/{id}', [JornadaController::class, 'destroy']);

    // Niveles educativos
    Route::get('/niveles', [NivelController::class, 'index']);
    Route::post('/niveles', [NivelController::class, 'store']);
    Route::get('/niveles/{id}', [NivelController::class, 'show']);
    Route::put('/niveles/{id}', [NivelController::class, 'update']);
    Route::delete('/niveles/{id}', [NivelController::class, 'destroy']);

    // Grados (pertenecen a un nivel)
    Route::get('/grados', [GradoController::class, 'index']);
    Route::post('/grados', [GradoController::class, 'store']);
    Route::get('/grados/{id}', [GradoController::class, 'show']);
    Route::put('/grados/{id}', [GradoController::class, 'update']);
    Route::delete('/grados/{id}', [GradoController::class, 'destroy']);

    // Grupos (grado + año lectivo + jornada)
    Route::get('/grupos', [GrupoController::class, 'index']);
    Route::post('/grupos', [GrupoController::class, 'store']);
    Route::get('/grupos/{id}', [GrupoController::class, 'show']);
    Route::put('/grupos/{id}', [GrupoController::class, 'update']);
    Route::delete('/grupos/{id}', [GrupoController::class, 'destroy']);

    // Bloques horarios
    Route::get('/bloques-horarios', [BloqueHorarioController::class, 'index']);
    Route::post('/bloques-horarios', [BloqueHorarioController::class, 'store']);
    Route::get('/bloques-horarios/{id}', [BloqueHorarioController::class, 'show']);
    Route::put('/bloques-horarios/{id}', [BloqueHorarioController::class, 'update']);
    Route::delete('/bloques-horarios/{id}', [BloqueHorarioController::class, 'destroy']);

    // Espacios físicos
    Route::get('/espacios-fisicos', [EspacioFisicoController::class, 'index']);
    Route::post('/espacios-fisicos', [EspacioFisicoController::class, 'store']);
    Route::get('/espacios-fisicos/{id}', [EspacioFisicoController::class, 'show']);
    Route::put('/espacios-fisicos/{id}', [EspacioFisicoController::class, 'update']);
    Route::delete('/espacios-fisicos/{id}', [EspacioFisicoController::class, 'destroy']);
});

// Pipeline de archivos por tenant (RN-AC-001..006): cualquier usuario autenticado
// del colegio (o el superadmin suplantando) puede subir a su cuota. La descarga
// se hace con URL firmada generada por StorageService (ruta central).
Route::post('/archivos', [StorageController::class, 'store']);

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
