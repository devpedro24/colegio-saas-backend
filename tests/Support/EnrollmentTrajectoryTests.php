<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\Area;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\Calificacion;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\Periodo;
use App\Models\Academico\SesionHorario;
use App\Models\User;
use App\Services\AttendanceSheetService;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use App\Support\Storage\StorageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

trait EnrollmentTrajectoryTests
{
    private function recordClassForTrajectory(Matricula $enrollment, Grupo $group, Periodo $period, Materia $subject, User $rector, $dayOfClass): array
    {
        $assignment = AsignacionDocente::create(['ano_lectivo_id' => $enrollment->ano_lectivo_id, 'grupo_id' => $group->id, 'materia_id' => $subject->id, 'docente_id' => $rector->id]);
        $section = ComponenteEvaluacion::create(['asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
            'nombre' => 'Actividades', 'modo' => 'SIMPLE_AVERAGE', 'version' => 1]);
        $activity = ActividadEvaluacion::create(['componente_id' => $section->id, 'nombre' => 'Taller', 'fecha' => $dayOfClass, 'version' => 1]);
        $grade = Calificacion::create(['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id, 'valor' => 4, 'updated_by' => $rector->id]);
        $day = ['lunes','martes','miercoles','jueves','viernes','sabado','domingo'][$dayOfClass->dayOfWeekIso - 1];
        $session = SesionHorario::create(['ano_lectivo_id' => $enrollment->ano_lectivo_id, 'asignacion_id' => $assignment->id,
            'grupo_id' => $group->id, 'materia_id' => $subject->id, 'docente_id' => $rector->id,
            'dia' => $day, 'hora_inicio' => '07:00', 'hora_fin' => '08:00']);
        $class = DB::table('asistencia_clases')->insertGetId(['sesion_horario_id' => $session->id, 'ano_lectivo_id' => $enrollment->ano_lectivo_id,
            'asignacion_id' => $assignment->id, 'periodo_id' => $period->id, 'grupo_id' => $group->id, 'materia_id' => $subject->id,
            'fecha' => $dayOfClass->toDateString(), 'hora_inicio' => '07:00', 'hora_fin' => '08:00']);
        DB::table('asistencia_marcas')->insert(['asistencia_clase_id' => $class, 'matricula_id' => $enrollment->id, 'estado' => 'ausente',
            'registrado_por_id' => $rector->id]);

        return [$grade, $assignment, $session];
    }

    public function test_graduate_return_reuses_anas_login_and_preserves_her_school_record(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $year = AnoLectivo::firstOrFail();
        $rector = User::where('role', 'rector')->firstOrFail();
        $credentials = ['email' => 'ana.regreso@example.test', 'password' => 'Testing123!'];
        $ana = User::create([...$credentials, 'name' => 'Ana Pruebas', 'role' => 'estudiante',
            'status' => 'active', 'must_change_password' => false]);
        $ana->assignRole('estudiante');
        $hash = $ana->fresh()->password;
        $token = OpaqueUrlToken::for('usuario', $ana->id);
        $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => Grado::firstOrFail()->id, 'nombre' => '01A', 'estado' => 'activo']);
        $enrollment = Matricula::create(['estudiante_id' => $ana->id, 'ano_lectivo_id' => $year->id, 'grupo_id' => $group->id, 'estado' => 'activa']);
        $period = Periodo::create(['ano_lectivo_id' => $year->id, 'orden' => 1, 'nombre' => 'Primero',
            'fecha_inicio' => '2027-01-01', 'fecha_fin' => '2027-03-31', 'estado' => 'cerrado']);
        $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Matemáticas', 'estado' => 'activo']);
        $subject = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id,
            'nombre' => 'Matemáticas', 'intensidad_horaria' => 4, 'estado' => 'activo']);
        [$grade] = $this->recordClassForTrajectory($enrollment, $group, $period, $subject, $rector, \Carbon\CarbonImmutable::parse('2027-02-01'));
        $scale = \App\Models\Academico\EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Institucional',
            'tipo' => 'numerica', 'valor_min' => 0, 'valor_max' => 5, 'decimales' => 1]);
        $method = \App\Models\Academico\MetodoAprobacion::create(['ano_lectivo_id' => $year->id,
            'calculo_nota' => 'promedio_simple', 'nota_minima' => 3, 'ambito' => 'materia']);
        $year->update(['estado' => 'cerrado', 'siee' => ['modo_asignatura' => 'SIMPLE_AVERAGE', 'escala_id' => $scale->id, 'metodo_id' => $method->id]]);
        $snapshot = $enrollment->fresh()->getRawOriginal();
        $originalGrade = $grade->fresh()->getRawOriginal();
        $cookie = \App\Support\Auth\BrowserAuthCookies::TENANT;
        $this->withoutHeader('Authorization');
        app('auth')->forgetGuards();
        $old = $this->postJson($this->base.'/login', $credentials)->assertOk()->getCookie($cookie, false)->getValue();
        $asStaff = function () {
            app('auth')->forgetGuards();
            $this->withToken($this->staffToken);
        };
        $asStaff();
        $permission = $this->base.'/rbac/roles/rector/permissions/';
        $this->putJson($permission.'estudiantes.egreso.gestionar', ['granted' => true])->assertOk();
        $asStaff();
        $url = $this->base.'/usuarios/'.$token;
        $this->postJson($url.'/egreso')->assertOk()->assertJsonPath('data.status', 'egresado');
        $this->withoutHeader('Authorization');
        app('auth')->forgetGuards();
        $this->postJson($this->base.'/login', $credentials)->assertUnprocessable();
        $asStaff();
        $this->putJson($url, ['status' => 'active'])->assertUnprocessable();
        $reason = ['motivo' => 'El colegio autoriza el regreso de Ana después de verificar su expediente.'];
        $candidates = $this->base.'/usuarios/reincorporacion-candidatos';
        $this->getJson($candidates)->assertForbidden();
        $this->postJson($url.'/reincorporacion', $reason)->assertForbidden();
        $this->putJson($permission.'estudiantes.reincorporacion.autorizar', ['granted' => true])->assertOk();
        $asStaff();
        $this->getJson($candidates)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.url_token', $token);
        $this->postJson($url.'/reincorporacion', ['motivo' => '  '])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->postJson($url.'/reincorporacion', [...$reason, 'sede_url_token' => str_repeat('x', 24)])->assertNotFound();
        $this->assertSame('egresado', $ana->fresh()->status);
        $this->postJson($url.'/reincorporacion', $reason)->assertOk()->assertExactJson(['data' => ['url_token' => $token, 'status' => 'active']]);
        $this->postJson($url.'/reincorporacion', $reason)->assertUnprocessable();
        $this->getJson($candidates)->assertOk()->assertJsonCount(0, 'data');
        $events = \App\Models\AuditLog::where('recurso', 'usuario')->where('recurso_id', (string) $ana->id);
        $this->assertTrue((clone $events)->where('accion', 'EGRESO_MANUAL')->exists());
        $event = $events->where('accion', 'REINCORPORACION')->sole();
        $this->assertSame($rector->id, $event->actor_id);
        $this->assertSame($reason['motivo'], $event->motivo);
        $this->assertSame(['status' => 'egresado'], $event->valor_previo);
        $this->assertSame(['status' => 'active'], $event->valor_nuevo);
        $this->assertNotNull($event->created_at);

        $this->withoutHeader('Authorization')->withUnencryptedCookie($cookie, $old);
        app('auth')->forgetGuards();
        $this->getJson($this->base.'/me')->assertUnauthorized();
        $login = $this->postJson($this->base.'/login', $credentials)->assertOk()->assertJsonPath('user.email', $ana->email);
        $new = $login->getCookie($cookie, false)->getValue();
        $this->assertNotSame($old, $new);
        $this->withUnencryptedCookie($cookie, $new);
        app('auth')->forgetGuards();
        $this->getJson($this->base.'/me')->assertOk()->assertJsonPath('user.email', $ana->email);
        $this->getJson($this->base.'/asistencias/mis-faltas?ano_lectivo_token='.OpaqueUrlToken::for('ano-lectivo', $year->id))
            ->assertOk()->assertJsonCount(1, 'data.faltas')->assertJsonPath('data.faltas.0.materia', 'Matemáticas')
            ->assertJsonPath('data.faltas.0.fecha', '2027-02-01');
        $report = $this->getJson($this->base.'/evaluacion/boletines/'.OpaqueUrlToken::for('matricula', $enrollment->id).'?opaque=1')
            ->assertOk()->assertJsonPath('data.asignaturas.0.nombre', 'Matemáticas');
        $this->assertEquals(4, $report->json('data.asignaturas.0.periodos.0.display_value'));
        $this->assertSame($hash, $ana->fresh()->password);
        $this->assertSame($snapshot, Matricula::where('estudiante_id', $ana->id)->sole()->getRawOriginal());
        $this->assertSame($originalGrade, $grade->fresh()->getRawOriginal());
        $this->assertEquals(4, $grade->fresh()->valor);
    }

    public function test_graduate_return_respects_campus_capacity_and_records_the_authorizing_school_operator(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $rector = User::where('role', 'rector')->firstOrFail();
        $sede = \App\Models\Academico\Sede::create(['nombre' => 'Norte', 'capacidad_maxima' => 1, 'estado' => 'activa']);
        $campus = app(\App\Services\SedeProvisioner::class)->provisionAndAttach($this->school, $sede, [
            'slug' => 'norte', 'name' => 'Norte', 'coordinador_email' => null,
        ])['tenant'];
        $siteToken = OpaqueUrlToken::for('sede', $sede->id);
        $studentToken = $campus->run(function () {
            $ana = User::create(['name' => 'Ana Pruebas', 'email' => 'ana.norte@example.test',
                'password' => 'Testing123!', 'role' => 'estudiante', 'status' => 'egresado']);
            $ana->assignRole('estudiante');
            User::create(['name' => 'Estudiante con cupo', 'email' => 'cupo@example.test',
                'password' => 'Testing123!', 'role' => 'estudiante', 'status' => 'active']);
            return OpaqueUrlToken::for('usuario', $ana->id);
        });
        $this->putJson($this->base.'/rbac/roles/rector/permissions/estudiantes.reincorporacion.autorizar', ['granted' => true])->assertOk();
        app('auth')->forgetGuards();
        $url = $this->base.'/usuarios/'.$studentToken.'/reincorporacion';
        $reason = ['motivo' => 'Regreso autorizado a la sede Norte.'];
        $this->getJson($this->base.'/usuarios/reincorporacion-candidatos')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url_token', $studentToken)->assertJsonPath('data.0.sede_url_token', $siteToken);
        $this->postJson($url, $reason)->assertNotFound();
        $body = [...$reason, 'sede_url_token' => $siteToken];
        $this->postJson($url, $body)->assertUnprocessable()->assertJsonPath('message', 'Se alcanzó la capacidad asignada a esta sede.');
        $campus->run(function () {
            $this->assertSame('egresado', User::where('email', 'ana.norte@example.test')->sole()->status);
            $this->assertFalse(\App\Models\AuditLog::where('accion', 'REINCORPORACION')->exists());
        });
        $sede->update(['capacidad_maxima' => 2]);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.status', 'active');
        $campus->run(function () use ($rector, $reason) {
            $ana = User::where('email', 'ana.norte@example.test')->sole();
            $this->assertSame('active', $ana->status);
            $event = \App\Models\AuditLog::where('accion', 'REINCORPORACION')->sole();
            $this->assertSame((string) $ana->id, $event->recurso_id);
            $this->assertSame($rector->email, $event->actor_email);
            $this->assertSame('rector', $event->actor_rol);
            $this->assertNull($event->actor_id);
            $this->assertSame($reason['motivo'], $event->motivo);
        });
        $this->assertFalse(User::where('email', 'ana.norte@example.test')->exists());
    }

    public function test_planned_year_allows_a_correction_before_classes_only_within_the_current_school(): void
    {
        $other = app(\App\Services\TenantProvisioner::class)->provision(['name' => 'Otra escuela', 'slug' => 'otra-escuela',
            'plan' => 'premium', 'rector_email' => 'rector@otra-escuela.test'])['tenant'];
        $foreignToken = $other->run(fn () => OpaqueUrlToken::for('grupo', 1));
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $today = now(tenant()->zonaHorariaInstitucional())->startOfDay();
        $year = AnoLectivo::firstOrFail();
        $year->update(['estado' => 'planificado', 'fecha_inicio' => $today->copy()->addMonth(), 'fecha_fin' => $today->copy()->addYear()]);
        $student = User::create(['name' => 'Estudiante nuevo', 'email' => 'antes-de-clase@example.test',
            'role' => 'estudiante', 'password' => 'Testing123!', 'status' => 'active']);
        $groupData = ['ano_lectivo_id' => $year->id, 'grado_id' => Grado::firstOrFail()->id, 'estado' => 'activo'];
        $a = Grupo::create([...$groupData, 'nombre' => 'A']);
        $b = Grupo::create([...$groupData, 'nombre' => 'B']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'ano_lectivo_id' => $year->id, 'grupo_id' => $a->id, 'estado' => 'activa']);
        $file = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $enrollment->id);
        $change = ['grupo_token' => $foreignToken, 'fecha_efectiva' => $today->toDateString(), 'motivo' => 'Corrección antes del inicio de clases.', 'version' => 0];
        $this->postJson($file.'/correcciones', $change)->assertNotFound();
        $this->postJson($file.'/correcciones', [...$change, 'grupo_token' => OpaqueUrlToken::for('grupo', $b->id)])->assertNoContent();
        $this->getJson($file)->assertOk()->assertJsonPath('data.grupo', 'Primero / B')
            ->assertJsonPath('data.trayectoria.resumen.notas', 0)->assertJsonPath('data.trayectoria.resumen.asistencias', 0);
    }

    public function test_rectification_links_private_evidence_and_preserves_the_intermediate_class(): void
    {
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $year = AnoLectivo::firstOrFail();
        $today = now(tenant()->zonaHorariaInstitucional())->startOfDay();
        $firstDay = $today->copy()->subWeek();
        $middleDay = $today->copy()->subDays(3);
        $year->update(['fecha_inicio' => $firstDay, 'fecha_fin' => $today->copy()->addMonth()]);
        $firstGrade = Grado::firstOrFail();
        $secondGrade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $firstGrade->nivel_id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $groupData = ['ano_lectivo_id' => $year->id, 'estado' => 'activo'];
        $origin = Grupo::create([...$groupData, 'grado_id' => $firstGrade->id, 'nombre' => 'A']);
        $intermediate = Grupo::create([...$groupData, 'grado_id' => $secondGrade->id, 'nombre' => 'A']);
        $final = Grupo::create([...$groupData, 'grado_id' => $firstGrade->id, 'nombre' => 'B']);
        $rector = User::where('role', 'rector')->firstOrFail();
        $student = User::create(['name' => 'Estudiante de prueba', 'email' => 'expediente@example.test', 'role' => 'estudiante',
            'password' => 'Testing123!', 'status' => 'active']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'ano_lectivo_id' => $year->id, 'grupo_id' => $origin->id, 'estado' => 'activa']);
        $enrollment->forceFill(['created_at' => $firstDay->copy()->subDay()])->save();
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $file = $this->base.'/matriculas/'.$token;
        $pdfBytes = fn (string $name) => "%PDF-1.4\n%Documento de prueba ".$name."\n%%EOF";
        $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, $pdfBytes($name));
        $headers = ['Accept' => 'application/json'];
        $change = ['grupo_token' => OpaqueUrlToken::for('grupo', $intermediate->id), 'fecha_efectiva' => $firstDay->toDateString(),
            'version' => 0, 'motivo' => 'Ubicación indicada al iniciar las clases.', 'soportes' => [$pdf('solicitud.pdf')]];
        $this->post($file.'/correcciones', $change, $headers)->assertNoContent();

        $period = Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primero', 'orden' => 1, 'peso' => 100,
            'fecha_inicio' => $firstDay, 'fecha_fin' => $today, 'estado' => 'abierto']);
        $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Ciencias', 'estado' => 'activo']);
        $subject = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id, 'nombre' => 'Biología', 'intensidad_horaria' => 2, 'estado' => 'activo']);
        [$grade, $assignment] = $this->recordClassForTrajectory($enrollment, $intermediate, $period, $subject, $rector, $middleDay);
        $originalGrade = $grade->fresh()->getRawOriginal();
        $mark = fn () => DB::table('asistencia_marcas')->where('matricula_id', $enrollment->id)->sole();
        $originalMark = $mark();
        $amend = ['grupo_token' => OpaqueUrlToken::for('grupo', $final->id), 'fecha_efectiva' => $today->toDateString(),
            'motivo' => 'El acta del colegio asigna Primero B.', 'version' => 1, 'rectifica_version' => 1];
        $operator = User::create(['name' => 'Gestor', 'email' => 'gestor-mov@example.test', 'role' => 'docente', 'password' => 'Testing123!', 'status' => 'active']);
        $operator->givePermissionTo(['academico.matriculas.gestionar', 'academico.matriculas.corregir']);
        app('auth')->forgetGuards();
        $this->withToken($operator->createToken('test')->plainTextToken)->postJson($file.'/correcciones', $amend)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($file.'/correcciones', [...$amend, 'motivo' => ' '])->assertUnprocessable();
        $this->postJson($file.'/correcciones', [...$amend, 'fecha_efectiva' => $firstDay->copy()->subDay()->toDateString()])->assertUnprocessable();
        $this->postJson($file.'/correcciones', [...$amend, 'rectifica_version' => 99])->assertNotFound();
        $text = UploadedFile::fake()->createWithContent('aviso.txt', 'solo texto');
        $invalidPdf = new UploadedFile($text->getRealPath(), 'aviso.pdf', test: true);
        $this->post($file.'/correcciones', [...$amend, 'soportes' => [$invalidPdf]], $headers)->assertUnprocessable();
        $this->app->instance(FileScanner::class, new class implements FileScanner {
            private int $calls = 0;
            public function scan(string $disk, string $path): void
            {
                if (++$this->calls === 2) throw new StorageException('Archivo rechazado en la prueba.');
            }
        });
        $this->post($file.'/correcciones', [...$amend, 'soportes' => [$pdf('acta.pdf'), $pdf('anexo.pdf')]], $headers)->assertUnprocessable();
        $this->assertSame($intermediate->id, $enrollment->fresh()->grupo_id);
        $this->assertCount(1, Storage::disk('tenant')->allFiles());
        $this->assertSame(1, DB::table('matricula_movimiento_soportes')->count());
        $this->app->instance(FileScanner::class, new NullScanner);
        $this->post($file.'/correcciones', [...$amend, 'soportes' => [$pdf('acta.pdf')]], $headers)->assertNoContent();
        $this->post($file.'/correcciones', [...$amend, 'soportes' => [$pdf('acta.pdf')]], $headers)->assertNoContent();
        $this->postJson($file.'/correcciones', [...$amend, 'version' => 2])->assertConflict();
        $this->postJson($file.'/correcciones', $amend)->assertConflict();
        $later = ['movimiento_version' => 2, 'motivo' => 'Se incorpora el anexo firmado.', 'soportes' => [$pdf('anexo.pdf')]];
        $this->post($file.'/soportes', $later, $headers)->assertNoContent();
        $this->post($file.'/soportes', $later, $headers)->assertNoContent();
        $history = $this->getJson($file)->assertOk()->assertJsonPath('data.grupo', 'Primero / B')
            ->assertJsonPath('data.trayectoria.movimientos.0.rectifica_version', 1)->assertJsonPath('data.trayectoria.movimientos.0.origen', 'Segundo / A')
            ->assertJsonPath('data.trayectoria.movimientos.0.motivo', $amend['motivo'])->assertJsonPath('data.trayectoria.movimientos.0.responsable', $rector->name)
            ->assertJsonPath('data.trayectoria.movimientos.1.destino', 'Segundo / A')->assertJsonPath('data.trayectoria.movimientos.1.motivo', $change['motivo'])
            ->assertJsonCount(2, 'data.trayectoria.movimientos')->assertJsonCount(2, 'data.trayectoria.movimientos.0.soportes')
            ->assertJsonPath('data.trayectoria.movimientos.0.soportes.1.motivo', $later['motivo']);
        $attachment = $history->json('data.trayectoria.movimientos.0.soportes.0.token');
        $download = $this->get($file.'/soportes/'.$attachment)->assertOk()->assertDownload('acta.pdf');
        $this->assertSame($pdfBytes('acta.pdf'), $download->streamedContent());
        $otherStudent = User::create(['name' => 'Otra estudiante', 'email' => 'otra@example.test', 'role' => 'estudiante', 'password' => 'Testing123!', 'status' => 'active']);
        $other = Matricula::create(['estudiante_id' => $otherStudent->id, 'ano_lectivo_id' => $year->id, 'grupo_id' => $origin->id, 'estado' => 'activa']);
        $this->getJson($this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $other->id).'/soportes/'.$attachment)->assertNotFound();
        $this->assertTrue(Matricula::rosterOn($year->id, $intermediate->id, $middleDay->toDateString())->whereKey($enrollment->id)->exists());
        $this->assertTrue(Matricula::rosterOn($year->id, $final->id, $today->toDateString())->whereKey($enrollment->id)->exists());
        $this->getJson($file.'/notas-de-actividades')->assertOk()->assertJsonPath('data.0.grado', 'Segundo')->assertJsonPath('data.0.grupo', 'A');
        $this->assertSame($originalGrade, $grade->fresh()->getRawOriginal());
        $this->assertEquals($originalMark, $mark());
        $sheet = app(AttendanceSheetService::class)->build($assignment, $period, true);
        $this->assertSame('ausente', collect($sheet['columnas'])->firstWhere('fecha', $middleDay->toDateString())['marcas'][$token]);
        $this->assertCount(3, Storage::disk('tenant')->allFiles());
        app('auth')->forgetGuards();
        $this->withToken($operator->createToken('test')->plainTextToken)->post($file.'/soportes', $later, $headers)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->get($file.'/soportes/'.$attachment, $headers)->assertForbidden();
    }

    public function test_early_promotion_requires_consent_recommendation_council_decision_and_preserves_first_grade_history(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2027-02-19 11:00', 'America/Bogota'));
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $today = now(tenant()->zonaHorariaInstitucional())->startOfDay();
        $old = $today->copy()->subWeek();
        $year = AnoLectivo::firstOrFail();
        $year->update(['fecha_inicio' => $old, 'fecha_fin' => $today->copy()->addMonth()]);
        $period = Periodo::create(['ano_lectivo_id' => $year->id, 'orden' => 1, 'nombre' => 'Primer periodo', 'peso' => 25,
            'fecha_inicio' => $old, 'fecha_fin' => $today, 'estado' => 'abierto']);
        $first = Grado::firstOrFail();
        $makeGroup = function ($name) use ($year, $first) {
            $grade = $name === 'Primero' ? $first : Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $first->nivel_id,
                'nombre' => $name, 'estado' => 'activo']);
            return Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'nombre' => 'A', 'estado' => 'activo']);
        };
        $origin = $makeGroup('Primero');
        $next = $makeGroup('Segundo');
        $skip = $makeGroup('Tercero');
        $student = User::create(['name' => 'Ana Promoción', 'email' => 'ana-promocion@example.test',
            'role' => 'estudiante', 'password' => 'Testing123!', 'status' => 'active']);
        $rector = User::where('role', 'rector')->firstOrFail();
        $enrollment = Matricula::create(['grupo_id' => $origin->id, 'estudiante_id' => $student->id, 'ano_lectivo_id' => $year->id, 'estado' => 'activa']);
        $enrollment->forceFill(['created_at' => $old->copy()->subDay()])->save();
        $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Matemáticas', 'estado' => 'activo']);
        $subject = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id, 'nombre' => 'Matemáticas', 'intensidad_horaria' => 4, 'estado' => 'activo']);
        [$grade] = $this->recordClassForTrajectory($enrollment, $origin, $period, $subject, $rector, $old);
        $gradeBefore = $grade->fresh()->getRawOriginal();
        $attendance = fn () => DB::table('asistencia_marcas')->where('matricula_id', $enrollment->id)->get();
        $attendanceBefore = $attendance();
        $url = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $enrollment->id);
        $cases = $url.'/promociones-anticipadas';
        $pdf = fn ($name) => UploadedFile::fake()->createWithContent($name.'.pdf', "%PDF-1.4\n".$name."\n%%EOF");
        $recommend = ['grupo_token' => OpaqueUrlToken::for('grupo', $next->id), 'version' => 0, 'fecha_recomendacion' => $today->toDateString(),
            'fecha_consentimiento' => $old->toDateString(), 'recomendacion' => 'El Consejo Académico documentó el desempeño superior cognitivo, personal y social.'];
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.trayectoria.promocion_anticipada.destinos');
        $this->postJson($cases, $recommend)->assertUnprocessable()->assertJsonValidationErrors('consentimiento');
        $this->postJson($cases, [...$recommend, 'grupo_token' => OpaqueUrlToken::for('grupo', $skip->id), 'consentimiento' => $pdf('consentimiento')])->assertUnprocessable();
        $this->postJson($cases, [...$recommend, 'fecha_recomendacion' => $old->copy()->subDay()->toDateString(), 'consentimiento' => $pdf('consentimiento')])->assertUnprocessable();
        $coordinator = User::create(['name' => 'Coordinación', 'email' => 'coord-promocion@example.test', 'role' => 'coord_academico', 'password' => 'Testing123!', 'status' => 'active']);
        $coordinator->givePermissionTo(['academico.matriculas.gestionar', \App\Services\Ingreso\EarlyPromotions::RECOMMEND]);
        app('auth')->forgetGuards();
        $this->withToken($coordinator->createToken('test')->plainTextToken);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($cases, [...$recommend, 'consentimiento' => $pdf('consentimiento')])->assertNoContent();
        }
        $record = $this->getJson($url)->assertOk()->assertJsonPath('data.grupo', 'Primero / A')->assertJsonPath('data.trayectoria.version', 0)
            ->assertJsonCount(1, 'data.trayectoria.promocion_anticipada.expedientes')->json('data.trayectoria.promocion_anticipada.expedientes.0');
        $caseUrl = $cases.'/'.$record['token'];
        $certificate = $caseUrl.'/constancia';
        $this->postJson($certificate)->assertForbidden();
        $decision = ['fecha_decision' => $today->toDateString(), 'decision' => 'aprobada', 'acta_numero' => 'CD-01'];
        $this->postJson($caseUrl.'/decision', $decision)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
        $this->postJson($certificate)->assertUnprocessable();
        $this->get($certificate.'/pdf')->assertNotFound();
        $this->postJson($caseUrl.'/decision', $decision)->assertUnprocessable()->assertJsonValidationErrors('acta');
        $this->postJson($cases.'/1/decision', [...$decision, 'acta' => $pdf('acta')])->assertNotFound();
        $this->postJson($cases.'/'.str_repeat('x', 24).'/decision', [...$decision, 'acta' => $pdf('acta')])->assertNotFound();
        $period->update(['estado' => 'cerrado']);
        $this->postJson($caseUrl.'/decision', [...$decision, 'acta' => $pdf('acta')])->assertUnprocessable();
        // Simulate a different current period, even if the first has been reopened for grade entry.
        $period->update(['estado' => 'abierto', 'fecha_fin' => $today->copy()->subDay()]);
        $this->postJson($caseUrl.'/decision', [...$decision, 'acta' => $pdf('acta')])->assertUnprocessable();
        $period->update(['fecha_fin' => $today]);
        $next->update(['cupo_maximo' => 0]);
        $this->postJson($caseUrl.'/decision', [...$decision, 'acta' => $pdf('acta')])->assertUnprocessable();
        $next->update(['cupo_maximo' => 10]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($caseUrl.'/decision', [...$decision, 'acta' => $pdf('acta')])->assertNoContent();
        }
        $this->postJson($caseUrl.'/decision', [...$decision, 'acta_numero' => 'Otra', 'acta' => $pdf('acta')])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.grupo', 'Segundo / A')->assertJsonPath('data.trayectoria.version', 1)
            ->assertJsonPath('data.trayectoria.movimientos.0.promocion_anticipada', true)
            ->assertJsonPath('data.trayectoria.promocion_anticipada.expedientes.0.estado', 'aprobada')
            ->assertJsonPath('data.trayectoria.promocion_anticipada.expedientes.0.recomendado_por', $coordinator->name)
            ->assertJsonPath('data.trayectoria.promocion_anticipada.expedientes.0.decidido_por', $rector->name);
        $this->get($caseUrl.'/soportes/consentimiento')->assertDownload('consentimiento.pdf');
        $this->get($caseUrl.'/soportes/acta')->assertDownload('acta.pdf');
        $this->get($certificate.'/pdf')->assertNotFound();
        $this->postJson($cases.'/1/constancia')->assertNotFound();
        $this->postJson($cases.'/'.str_repeat('X', 24).'/constancia')->assertNotFound();
        $issuer = User::create(['name' => 'Lucía Torres', 'email' => 'secretaria-emision@example.test', 'role' => 'secretaria',
            'password' => 'Testing123!', 'status' => 'active']);
        $issuer->givePermissionTo(['academico.matriculas.gestionar', \App\Services\Ingreso\EarlyPromotionCertificates::ISSUE]);
        app('auth')->forgetGuards();
        $this->withToken($issuer->createToken('test')->plainTextToken);
        $this->getJson($url)->assertOk()->assertJsonPath('data.trayectoria.promocion_anticipada.firmante_previsto', 'Lucía Torres')
            ->assertJsonMissingPath('data.trayectoria.promocion_anticipada.expedientes.0.recomendacion');
        $this->get($caseUrl.'/soportes/consentimiento')->assertForbidden();
        $this->travel(3)->days();
        $this->postJson($certificate)->assertNoContent();
        $summary = $this->getJson($url)->assertOk()->json('data.trayectoria.promocion_anticipada.expedientes.0.constancia');
        $this->assertSame(['numero' => 'PA-000001', 'firmante' => 'Lucía Torres',
            'fecha_expedicion' => '2027-02-22', 'verificable' => true, 'con_anexo' => false], $summary);
        $download = $this->get($certificate.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('constancia-PA-000001.pdf')->getContent();
        $this->assertStringStartsWith('%PDF-', $download);
        if ($reader = (new \Symfony\Component\Process\ExecutableFinder)->find('pdftotext')) {
            $content = (new \Symfony\Component\Process\Process([$reader, '-layout', '-enc', 'UTF-8', '-', '-']))
                ->setInput($download)->mustRun()->getOutput();
            foreach (['Ana Promoción', 'PA-000001', 'Primero / A', 'Segundo / A', '19/02/2027', '22/02/2027', 'CD-01', 'Lucía Torres'] as $value) {
                $this->assertStringContainsString($value, $content);
            }
            $this->assertSame(1, preg_match('/(?:[A-F0-9]{4}-){5}[A-F0-9]{4}/', $content, $matches));
            app('auth')->forgetGuards();
            $this->withoutHeader('Authorization');
            $this->postJson($this->base.'/constancias/verificar', ['codigo' => strtolower(str_replace('-', ' ', $matches[0]))])
                ->assertOk()->assertExactJson(['data' => ['numero' => 'PA-000001', 'colegio' => 'Inscripciones', 'fecha_expedicion' => '2027-02-22']]);
        }
        foreach (['PA-000001', str_repeat('0', 24), []] as $invalid) {
            $this->postJson($this->base.'/constancias/verificar', ['codigo' => $invalid])->assertNotFound()->assertExactJson(['data' => null]);
        }
        $issuer->update(['name' => 'Otro nombre']);
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($certificate)->assertNoContent();
        $this->assertSame($download, $this->get($certificate.'/pdf')->assertOk()->getContent());
        $this->assertSame(1, DB::table('audit_logs')->where('recurso', 'constancia_promocion')->where('accion', 'CREATE')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('recurso', 'promocion_anticipada')->whereIn('accion', ['CREATE', 'UPDATE'])->count());
        $this->assertSame($gradeBefore, $grade->fresh()->getRawOriginal());
        $this->assertEquals($attendanceBefore, $attendance());
        $this->assertSame(1, Matricula::where('estudiante_id', $student->id)->count());
        $this->assertTrue(Matricula::rosterOn($year->id, $origin->id, $old->toDateString())->whereKey($enrollment->id)->exists());
        $this->assertTrue(Matricula::rosterOn($year->id, $next->id, $today->toDateString())->whereKey($enrollment->id)->exists());
        $this->getJson($url.'/notas-de-actividades')->assertOk()->assertJsonPath('data.0.grado', 'Primero')->assertJsonPath('data.0.materia', 'Matemáticas');
        $this->checkArchivedPromotionVerification($certificate);
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->get($certificate.'/pdf')->assertForbidden();
    }

    private function checkArchivedPromotionVerification(string $certificate): void
    {
        $table = DB::table(\App\Services\Ingreso\EarlyPromotionCertificates::TABLE);
        $issue = $table->firstOrFail();
        $old = new \FPDF();
        $old->AddPage();
        $old->SetFont('Helvetica', '', 14);
        $old->Cell(0, 12, 'Inscripciones - Ana - PA-000001');
        $original = $old->Output('S');
        Storage::disk('tenant')->put($issue->pdf_path, $original);
        $table->where('consecutivo', $issue->consecutivo)->update([
            'codigo_verificacion_hash' => null, 'emisor_verificacion' => null, 'pdf_sha256' => hash('sha256', $original),
        ]);
        $this->get($certificate.'/pdf-verificable')->assertNotFound();
        $this->postJson($certificate)->assertNoContent();
        $copy = $this->get($certificate.'/pdf-verificable')->assertOk()->assertDownload('constancia-PA-000001-verificable.pdf')->getContent();
        $this->assertNotSame($original, $copy);
        $this->postJson($certificate)->assertNoContent();
        $this->assertSame($original, $this->get($certificate.'/pdf')->assertOk()->getContent());
        $this->assertSame($copy, $this->get($certificate.'/pdf-verificable')->assertOk()->getContent());
        $this->assertSame(1, $table->count());
        if ($reader = (new \Symfony\Component\Process\ExecutableFinder)->find('pdftotext')) {
            $content = (new \Symfony\Component\Process\Process([$reader, '-layout', '-enc', 'UTF-8', '-', '-']))
                ->setInput($copy)->mustRun()->getOutput();
            $this->assertStringContainsString('Inscripciones - Ana - PA-000001', $content);
            $this->assertSame(1, preg_match('/(?:[A-F0-9]{4}-){5}[A-F0-9]{4}/', $content, $matches));
            $this->postJson($this->base.'/constancias/verificar', ['codigo' => $matches[0]])->assertOk()->assertJsonPath('data.numero', 'PA-000001');
        }
    }

    public function test_early_promotion_cannot_apply_a_stale_recommendation_and_keeps_the_declined_case(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $today = now(tenant()->zonaHorariaInstitucional())->startOfDay();
        $year = AnoLectivo::firstOrFail();
        $year->update(['fecha_inicio' => $today->copy()->subMonth(), 'fecha_fin' => $today->copy()->addMonth()]);
        Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primero', 'orden' => 1, 'peso' => 25,
            'estado' => 'abierto', 'fecha_inicio' => $today->copy()->subWeek(), 'fecha_fin' => $today->copy()->addWeek()]);
        $first = Grado::firstOrFail();
        $second = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $first->nivel_id, 'estado' => 'activo', 'nombre' => '2.º']);
        $group = fn ($grade, $name) => Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'nombre' => $name, 'estado' => 'activo']);
        $origin = $group($first, 'A');
        $parallel = $group($first, 'B');
        $target = $group($second, 'A');
        $enroll = function ($name) use ($year, $origin) {
            $user = User::create(['name' => $name, 'role' => 'estudiante', 'email' => $name.'@example.test', 'status' => 'active', 'password' => 'Testing123!']);
            return Matricula::create(['estudiante_id' => $user->id, 'grupo_id' => $origin->id, 'ano_lectivo_id' => $year->id, 'estado' => 'activa']);
        };
        $ana = $enroll('Ana');
        $other = $enroll('Rosa');
        $file = fn ($name) => UploadedFile::fake()->createWithContent($name.'.pdf', "%PDF-1.4\n".$name."\n%%EOF");
        $url = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $ana->id);
        $date = $today->toDateString();
        $this->postJson($url.'/promociones-anticipadas', ['grupo_token' => OpaqueUrlToken::for('grupo', $target->id),
            'version' => 0, 'fecha_consentimiento' => $date, 'consentimiento' => $file('firma'), 'fecha_recomendacion' => $date,
            'recomendacion' => 'Desempeño superior observado por el Consejo Académico.'])->assertNoContent();
        $case = $this->getJson($url)->assertOk()->json('data.trayectoria.promocion_anticipada.expedientes.0.token');
        $casePath = '/promociones-anticipadas/'.$case;
        $this->getJson($this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $other->id).$casePath.'/soportes/consentimiento')->assertNotFound();
        $this->postJson($url.'/correcciones', ['grupo_token' => OpaqueUrlToken::for('grupo', $parallel->id),
            'fecha_efectiva' => $date, 'version' => 0, 'motivo' => 'Corrección administrativa de grupo.'])->assertNoContent();
        $data = ['fecha_decision' => $date, 'acta_numero' => 'CD-02'];
        $this->postJson($url.$casePath.'/decision', [...$data, 'decision' => 'aprobada', 'acta' => $file('decision')])->assertConflict();
        $this->postJson($url.$casePath.'/decision', [...$data, 'decision' => 'no_aprobada', 'acta' => $file('decision')])->assertNoContent();
        $this->postJson($url.$casePath.'/constancia')->assertUnprocessable();
        $otherCaseUrl = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $other->id).$casePath;
        $this->postJson($otherCaseUrl.'/constancia')->assertNotFound();
        $this->get($otherCaseUrl.'/constancia/pdf')->assertNotFound();
        $anotherSchool = app(\App\Services\TenantProvisioner::class)->provision(['name' => 'Otro colegio', 'slug' => 'otro-colegio',
            'plan' => 'premium', 'rector_email' => 'rector@otro-colegio.test'])['tenant'];
        $foreignEnrollment = $anotherSchool->run(fn () => OpaqueUrlToken::for('matricula', $ana->id));
        $foreignCertificate = $this->base.'/matriculas/'.$foreignEnrollment.$casePath.'/constancia';
        $this->postJson($foreignCertificate)->assertNotFound();
        $this->get($foreignCertificate.'/pdf')->assertNotFound();
        $this->getJson($url)->assertOk()->assertJsonPath('data.grupo', 'Primero / B')->assertJsonPath('data.trayectoria.version', 1)
            ->assertJsonPath('data.trayectoria.promocion_anticipada.expedientes.0.estado', 'no_aprobada')
            ->assertJsonPath('data.trayectoria.promocion_anticipada.expedientes.0.origen', 'Primero / A')
            ->assertJsonPath('data.trayectoria.promocion_anticipada.puede_recomendar', true);
    }

    public function test_enrolled_student_can_change_grade_and_group_with_audited_dates_and_intact_academic_history(): void
    {
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $year = AnoLectivo::firstOrFail();
        $today = now(tenant()->zonaHorariaInstitucional())->startOfDay();
        $oldDay = $today->copy()->subWeeks(2);
        $moveDay = $today->copy()->subWeek()->toDateString();
        $year->update(['fecha_inicio' => $oldDay->copy()->subWeek(), 'fecha_fin' => $today->copy()->addMonth()]);
        $rector = User::where('role', 'rector')->firstOrFail();
        $student = User::create(['name' => 'Estudiante de prueba', 'email' => 'trayectoria@example.test',
            'role' => 'estudiante', 'password' => 'Testing123!', 'status' => 'active']);
        $first = Grado::firstOrFail();
        $second = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $first->nivel_id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $original = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $first->id, 'nombre' => 'A', 'estado' => 'activo']);
        $target = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $second->id, 'nombre' => 'B', 'estado' => 'activo', 'cupo_maximo' => 0]);
        $parallel = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $second->id, 'nombre' => 'C', 'estado' => 'activo']);
        $enrollment = Matricula::create(['estudiante_id' => $student->id, 'grupo_id' => $original->id, 'ano_lectivo_id' => $year->id, 'estado' => 'activa']);
        $enrollment->forceFill(['created_at' => $oldDay->copy()->subDay()])->save();
        $period = Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Primero', 'orden' => 1, 'peso' => 100,
            'fecha_inicio' => $oldDay, 'fecha_fin' => $today, 'estado' => 'abierto']);
        $area = Area::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Ciencias', 'estado' => 'activo']);
        $subject = Materia::create(['ano_lectivo_id' => $year->id, 'area_id' => $area->id,
            'nombre' => 'Biología', 'intensidad_horaria' => 2, 'estado' => 'activo']);
        [$grade, $assignment, $session] = $this->recordClassForTrajectory($enrollment, $original, $period, $subject, $rector, $oldDay);
        $oldMarks = DB::table('asistencia_marcas')->where('matricula_id', $enrollment->id)->get();
        $oldNotes = $grade->fresh()->getRawOriginal();
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $url = $this->base.'/matriculas/'.$token;
        $change = $url.'/correcciones';
        $data = ['grupo_token' => OpaqueUrlToken::for('grupo', $target->id), 'fecha_efectiva' => $moveDay, 'motivo' => 'Corrección del grado registrado.', 'version' => 0];
        $visitor = User::create(['name' => 'Gestor', 'email' => 'gestor@example.test', 'role' => 'docente', 'password' => 'Testing123!', 'status' => 'active']);
        $visitor->givePermissionTo('academico.matriculas.gestionar');
        app('auth')->forgetGuards();
        $this->withToken($visitor->createToken('test')->plainTextToken)->getJson($url)->assertOk()->assertJsonPath('data.trayectoria.puede_corregir', false);
        $this->postJson($change, $data)->assertForbidden();
        $this->getJson($url.'/notas-de-actividades')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->getJson($url)->assertOk()->assertJsonPath('data.trayectoria.resumen.notas', 1)
            ->assertJsonPath('data.trayectoria.resumen.asistencias', 1)->assertJsonPath('data.trayectoria.version', 0);
        $this->getJson($this->base.'/matriculas?estudiante_token='.OpaqueUrlToken::for('usuario', $student->id))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.token', $token);
        $this->getJson($this->base.'/matriculas?estudiante_token='.OpaqueUrlToken::for('usuario', $rector->id))->assertNotFound();
        $this->postJson($change, [...$data, 'motivo' => '   '])->assertUnprocessable();
        $this->postJson($change, [...$data, 'fecha_efectiva' => $oldDay->copy()->subDays(3)->toDateString()])->assertUnprocessable();
        $this->postJson($change, [...$data, 'fecha_efectiva' => $today->copy()->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson($change, [...$data, 'grupo_token' => str_repeat('x', 24)])->assertNotFound();
        $this->postJson($this->base.'/matriculas/1/correcciones', $data)->assertNotFound();
        $this->postJson($change, $data)->assertUnprocessable()->assertSee('cupo');
        $this->assertSame($original->id, $enrollment->fresh()->grupo_id);
        $target->update(['cupo_maximo' => 2]);
        $this->postJson($change, $data)->assertNoContent();
        $this->postJson($change, $data)->assertNoContent();
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.grupo', 'Segundo / B')
            ->assertJsonPath('data.trayectoria.version', 1)->assertJsonPath('data.trayectoria.movimientos.0.origen', 'Primero / A')
            ->assertJsonPath('data.trayectoria.movimientos.0.motivo', $data['motivo'])
            ->assertJsonPath('data.trayectoria.movimientos.0.responsable', $rector->name);
        $this->assertStringNotContainsString('matricula_id', $response->getContent());
        $this->assertSame(1, DB::table('audit_logs')->where('recurso', 'matricula_trayectoria')->where('actor_id', $rector->id)
            ->where('motivo', $data['motivo'])->count());
        $this->postJson($this->base.'/asistencias/solicitudes', ['matricula_token' => $token,
            'asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $assignment->id),
            'marcas' => [['sesion_token' => OpaqueUrlToken::for('sesion-horario', $session->id), 'fecha' => $oldDay->toDateString()]],
            'motivo' => 'Revisar la ausencia anterior al traslado.'])->assertCreated();
        $this->getJson($url.'/notas-de-actividades')->assertOk()->assertJsonPath('data.0.actividad', 'Taller')
            ->assertJsonPath('data.0.grado', 'Primero')->assertJsonPath('data.0.grupo', 'A');
        $sheet = app(AttendanceSheetService::class)->build($assignment, $period, true);
        $columns = collect($sheet['columnas'])->keyBy('fecha');
        $this->assertSame('ausente', $columns[$oldDay->toDateString()]['marcas'][$token]);
        $this->assertNotContains($token, $columns[$moveDay]['matriculas']);
        $this->assertTrue(Matricula::rosterOn($year->id, $original->id, $oldDay->toDateString())->whereKey($enrollment->id)->exists());
        $this->assertTrue(Matricula::rosterOn($year->id, $target->id, $moveDay)->whereKey($enrollment->id)->exists());
        $next = [...$data, 'grupo_token' => OpaqueUrlToken::for('grupo', $parallel->id), 'fecha_efectiva' => $today->toDateString()];
        $this->postJson($change, $next)->assertConflict();
        $this->postJson($change, [...$next, 'version' => 1, 'fecha_efectiva' => $oldDay->toDateString()])->assertUnprocessable();
        $this->postJson($change, [...$next, 'version' => 1])->assertNoContent();
        $this->postJson($change, [...$next, 'version' => 2, 'grupo_token' => OpaqueUrlToken::for('grupo', $original->id)])->assertNoContent();
        $this->assertSame(3, DB::table('matricula_movimientos')->where('matricula_id', $enrollment->id)->count());
        $this->assertSame(1, Matricula::where('estudiante_id', $student->id)->count());
        $this->assertSame($oldNotes, $grade->fresh()->getRawOriginal());
        $this->assertEquals($oldMarks, DB::table('asistencia_marcas')->where('matricula_id', $enrollment->id)->get());
        $year->update(['estado' => 'cerrado']);
        $this->postJson($change, [...$next, 'version' => 3])->assertUnprocessable();
    }
}
