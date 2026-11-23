<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Academico\ActividadEvaluacion;
use App\Models\Academico\AnoLectivo;
use App\Models\Academico\AsignacionDocente;
use App\Models\Academico\BoletinEmision;
use App\Models\Academico\ComponenteEvaluacion;
use App\Models\Academico\Calificacion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Grado;
use App\Models\Academico\Grupo;
use App\Models\Academico\Materia;
use App\Models\Academico\Matricula;
use App\Models\Academico\MetodoAprobacion;
use App\Models\Academico\Periodo;
use App\Models\User;
use App\Services\AcademicPromotionService;
use App\Services\Ingreso\CertifiedExternalGrades;
use App\Services\Ingreso\EntryAssessments;
use Illuminate\Support\Collection;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\FileScanner;
use App\Support\Storage\NullScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait CertifiedExternalGradeTests
{
    public function test_third_period_transfer_recognizes_two_external_math_grades_from_one_private_certificate(): void
    {
        $otherSchool = app(\App\Services\TenantProvisioner::class)->provision(['name' => 'Otra escuela', 'slug' => 'externas-otra',
            'plan' => 'premium', 'rector_email' => 'rector@externas-otra.test'])['tenant'];
        $foreignEnrollmentToken = $otherSchool->run(fn () => OpaqueUrlToken::for('matricula', 1));
        [$year, $periods, $group, $math, $student, $enrollment] = $this->thirdPeriodEnrollment();
        $path = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $enrollment->id).'/definitivas-externas';
        $pdf = "%PDF-1.4\n% Certificado de Matemáticas de prueba\n%%EOF";
        $data = ['fecha_ingreso' => $periods[2]->fecha_inicio->toDateString(), 'colegio_origen' => 'Colegio de origen',
            'grado_origen' => 'Primero', 'escala_origen' => '0 a 100', 'resultados' => []];
        foreach (['80','90'] as $i => $value) {
            $data['resultados'][] = ['materia_origen' => 'Matemáticas', 'periodo_origen' => $periods[$i]->nombre,
                'desde' => $periods[$i]->fecha_inicio->toDateString(), 'hasta' => $periods[$i]->fecha_fin->toDateString(), 'valor_original' => $value];
        }
        $submit = fn ($payload) => $this->post($path, [...$payload, 'certificado' => UploadedFile::fake()->createWithContent('constancia.pdf', $pdf)], ['Accept' => 'application/json']);
        $this->postJson($path, $data)->assertUnprocessable()->assertJsonValidationErrors('certificado');
        $bad = $data;
        $bad['resultados'][0]['hasta'] = $data['fecha_ingreso'];
        $submit($bad)->assertUnprocessable();
        $submit($data)->assertNoContent();
        $retry = $data;
        $retry['resultados'] = array_map(fn ($row) => array_reverse($row, true), $data['resultados']);
        $submit($retry)->assertNoContent();
        $file = $this->getJson($path)->assertOk()->assertJsonCount(1, 'certificados')->assertJsonCount(2, 'certificados.0.resultados')
            ->assertJsonPath('certificados.0.periodo_ingreso', 'Tercero')->assertJsonPath('certificados.0.resultados.0.decision', null)
            ->assertJsonPath('escala', null)->json('certificados.0');
        $this->assertCount(1, Storage::disk('tenant')->allFiles());
        $download = $this->get($path.'/certificados/'.$file['archivo_token'])->assertOk()->assertDownload('constancia.pdf');
        $this->assertSame($pdf, $download->streamedContent());

        [$scale, $rector, $assignment] = $this->configureMathGrades($year, $math, $group);
        $old = $this->localMathActivity($assignment, $periods[0]);
        Calificacion::create(['matricula_id' => $enrollment->id, 'actividad_id' => $old->id, 'valor' => '1.0', 'updated_by' => $rector->id]);
        $reportPath = $this->base.'/evaluacion/boletines/'.OpaqueUrlToken::for('matricula', $enrollment->id);
        $this->getJson($reportPath)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.display_value', '1')
            ->assertJsonPath('data.asignaturas.0.periodos.1.estado', 'pendiente');
        $version = $this->getJson($path)->assertOk()->json('escala.version');
        $approve = fn ($i, $payload) => $this->postJson($path.'/'.$file['resultados'][$i]['token'].'/aprobar', $payload);
        $decision = ['materia_token' => OpaqueUrlToken::for('materia', $math->id), 'periodo_token' => OpaqueUrlToken::for('periodo', $periods[0]->id),
            'version_escala' => $version, 'valor' => '4.0', 'criterio' => 'Competencias equivalentes. Conversión proporcional aprobada de 100 a 5.'];
        $approve(0, [...$decision, 'periodo_token' => OpaqueUrlToken::for('periodo', $periods[2]->id)])->assertUnprocessable();
        $approve(0, [...$decision, 'valor' => '8.0'])->assertUnprocessable();
        $approve(0, [...$decision, 'valor' => '3.75'])->assertUnprocessable();
        $this->getJson($this->base.'/matriculas/1/definitivas-externas')->assertNotFound();
        $this->getJson($this->base.'/matriculas/'.$foreignEnrollmentToken.'/definitivas-externas')->assertNotFound();
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', $this->getJson($path)->getContent());
        $other = User::create(['name' => 'Otra estudiante', 'email' => 'otra-traslado@example.test', 'role' => 'estudiante', 'status' => 'active', 'password' => 'Testing123!']);
        $otherEnrollment = Matricula::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id, 'estudiante_id' => $other->id, 'estado' => 'activa']);
        $otherPath = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $otherEnrollment->id).'/definitivas-externas';
        $this->getJson($otherPath.'/certificados/'.$file['archivo_token'])->assertNotFound();
        $this->postJson($otherPath.'/'.$file['resultados'][0]['token'].'/aprobar', $decision)->assertNotFound();
        $approve(0, [...$decision, 'version_escala' => str_repeat('a', 64)])->assertConflict();
        $approve(0, $decision)->assertNoContent();
        $approve(0, $decision)->assertNoContent();
        $approve(0, [...$decision, 'valor' => '3.5'])->assertConflict();
        $approve(1, $decision)->assertConflict();
        $approve(1, [...$decision, 'valor' => '4.5', 'periodo_token' => OpaqueUrlToken::for('periodo', $periods[1]->id)])->assertNoContent();
        $scale->update(['valor_max' => 10]);
        $this->getJson($path)->assertOk()->assertJsonPath('certificados.0.resultados.0.valor_original', '80')
            ->assertJsonPath('certificados.0.resultados.1.valor_original', '90')->assertJsonPath('certificados.0.resultados.0.decision.valor', '4')
            ->assertJsonPath('certificados.0.resultados.1.decision.valor', '4.5')->assertJsonPath('certificados.0.resultados.1.decision.periodo', 'Segundo')
            ->assertJsonPath('certificados.0.resultados.1.decision.siee.version', $version);
        $this->assertSame(['cerrado','cerrado','abierto','planificado'], Periodo::orderBy('orden')->pluck('estado')->all());
        $this->assertSame(1, Calificacion::where('matricula_id', $enrollment->id)->count());
        $this->assertSame(1, ActividadEvaluacion::count());
        $this->assertSame(2, DB::table('audit_logs')->where('recurso', 'matricula_equivalencia')->where('accion', 'UPDATE')->count());
        $visual = EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nivel_educativo' => 'primaria', 'nombre' => 'Desempeño', 'tipo' => 'imagenes']);
        $visual->opciones()->create(['nombre' => 'Básico', 'orden' => 1, 'valor_equivalente' => 3, 'aprueba' => true, 'emoji' => '⭐']);
        $visual->opciones()->create(['nombre' => 'Alto', 'orden' => 2, 'valor_equivalente' => 4, 'aprueba' => true, 'emoji' => '🌟']);
        $qualitative = [...$data, 'escala_origen' => 'Conceptual', 'resultados' => [[...$data['resultados'][0], 'valor_original' => 'Logrado']]];
        $this->post($otherPath, [...$qualitative, 'certificado' => UploadedFile::fake()->createWithContent('concepto.pdf', $pdf)], ['Accept' => 'application/json'])->assertNoContent();
        $visualForm = $this->getJson($otherPath)->assertOk()->json();
        $review = $otherPath.'/'.$visualForm['certificados'][0]['resultados'][0]['token'].'/aprobar';
        $mapping = [...$decision, 'version_escala' => $visualForm['escala']['version'], 'criterio' => 'El concepto certificado corresponde al desempeño Alto.'];
        $this->postJson($review, $mapping)->assertUnprocessable();
        unset($mapping['valor']);
        $this->postJson($review, [...$mapping, 'opcion_token' => $visualForm['escala']['opciones'][1]['token']])->assertNoContent();
        $this->getJson($otherPath)->assertOk()->assertJsonPath('certificados.0.resultados.0.valor_original', 'Logrado')
            ->assertJsonPath('certificados.0.resultados.0.decision.categoria', 'Alto')->assertJsonPath('certificados.0.resultados.0.decision.valor', '4');
        $operator = User::create(['name' => 'Registro', 'email' => 'registro@example.test', 'role' => 'docente', 'status' => 'active', 'password' => 'Testing123!']);
        $operator->givePermissionTo(['academico.matriculas.gestionar', CertifiedExternalGrades::REGISTER]);
        app('auth')->forgetGuards();
        $this->withToken($operator->createToken('test')->plainTextToken)->getJson($path)->assertOk()->assertJsonPath('puede_aprobar', false);
        $approve(0, $decision)->assertForbidden();
        $choicePath = $path.'/'.$file['resultados'][0]['token'].'/eleccion';
        $this->postJson($choicePath, [])->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->getJson($path.'/certificados/'.$file['archivo_token'])->assertForbidden();

        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
        $visual->delete();
        $scale->update(['valor_max' => 5]);
        $case = $this->getJson($path)->assertOk()->assertJsonPath('puede_elegir', true)
            ->assertJsonPath('certificados.0.resultados.0.eleccion.fuente', null)
            ->assertJsonPath('certificados.0.resultados.1.eleccion', null)->json('certificados.0.resultados.0.eleccion');
        $this->assertEquals(1, $case['colegio']);
        $this->assertEquals(4, $case['certificado']);
        // Upgrading an archived enrollment must preserve the already official period grade.
        $existing = DB::table(CertifiedExternalGrades::RESULTS)->where('token', $file['resultados'][0]['token'])->first();
        $priorState = $year->estado;
        $year->update(['estado' => 'archivado']);
        \App\Services\Ingreso\GradeSourceDecisions::capture($existing, historical: true);
        $this->getJson($path)->assertOk()->assertJsonPath('puede_elegir', false)
            ->assertJsonPath('certificados.0.resultados.0.eleccion.historica', true)
            ->assertJsonPath('certificados.0.resultados.0.eleccion.fuente', 'certificado');
        $year->update(['estado' => $priorState]);
        \App\Services\Ingreso\GradeSourceDecisions::capture($existing, historical: true);
        $this->getJson($reportPath)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.origen', 'pendiente_eleccion')
            ->assertJsonPath('data.asignaturas.0.periodos.0.estado', 'pendiente');
        $choice = ['huella' => $case['huella'], 'version' => 0, 'fuente' => 'colegio', 'motivo' => 'Se verificó la planilla de Ana.'];
        $this->postJson($choicePath, [...$choice, 'motivo' => '   '])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->postJson($otherPath.'/'.$file['resultados'][0]['token'].'/eleccion', $choice)->assertNotFound();
        $this->postJson($choicePath, $choice)->assertNoContent();
        $this->postJson($choicePath, $choice)->assertNoContent();
        $this->getJson($reportPath)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.origen', 'colegio')
            ->assertJsonPath('data.asignaturas.0.periodos.0.display_value', '1')
            ->assertJsonPath('data.asignaturas.0.periodos.1.display_value', '4.5');
        $revised = [...$choice, 'fuente' => 'certificado', 'motivo' => 'El certificado cubre el periodo completo de Matemáticas.'];
        $this->postJson($choicePath, $revised)->assertConflict();
        $this->postJson($choicePath, [...$revised, 'version' => 1])->assertNoContent();
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'certificados.0.resultados.0.eleccion.historial')
            ->assertJsonPath('certificados.0.resultados.0.eleccion.historial.0.motivo', $revised['motivo'])
            ->assertJsonPath('certificados.0.resultados.0.eleccion.historial.1.fuente', 'colegio')
            ->assertJsonPath('certificados.0.resultados.0.eleccion.historial.1.responsable', $rector->name);
        $this->assertSame(2, DB::table('audit_logs')->where('recurso', 'matricula_eleccion_definitiva')->count());
        $this->verifyRecognizedMathYear($enrollment, $assignment, $periods, $old, $rector, $year, $reportPath);
    }

    public function test_individual_closed_grade_records_only_anas_first_period_math(): void
    {
        $foreign = app(\App\Services\TenantProvisioner::class)->provision(['name' => 'Otro colegio', 'slug' => 'otro-cierre',
            'plan' => 'premium', 'rector_email' => 'rector@otro-cierre.test'])['tenant'];
        $foreignToken = $foreign->run(fn () => OpaqueUrlToken::for('matricula', 1));
        [$year, $periods, $group, $math, $ana, $enrollment] = $this->thirdPeriodEnrollment();
        [, $rector, $assignment] = $this->configureMathGrades($year, $math, $group);
        $peer = User::create(['name' => 'Estudiante del grupo', 'email' => 'peer@example.test', 'password' => 'Testing123!', 'role' => 'estudiante', 'status' => 'active']);
        $peerEnrollment = Matricula::create(['ano_lectivo_id' => $year->id, 'estudiante_id' => $peer->id, 'grupo_id' => $group->id, 'estado' => 'activa']);
        $clerk = User::create(['name' => 'Registro académico', 'email' => 'registro@example.test', 'password' => 'Testing123!', 'role' => 'docente', 'status' => 'active']);
        $clerk->givePermissionTo(['academico.matriculas.gestionar', EntryAssessments::REGISTER]);
        $clerkToken = $clerk->createToken('test')->plainTextToken;
        $login = function ($token) { app('auth')->forgetGuards(); $this->withToken($token); };
        $anaToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $peerToken = OpaqueUrlToken::for('matricula', $peerEnrollment->id);
        $url = fn ($token) => $this->base.'/matriculas/'.$token.'/autorizaciones-cierre';
        $path = $url($anaToken);
        $period = fn ($i) => OpaqueUrlToken::for('periodo', $periods[$i]->id);
        $activity = $this->localMathActivity($assignment, $periods[0]);
        $sheet = $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $assignment->id).'/'.$period(0);
        $ordinary = fn ($token) => $this->putJson($sheet, ['notas' => [['matricula_token' => $token, 'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id), 'valor' => '4.0', 'version' => 0]]]);
        $form = $this->getJson($path)->assertOk()->assertJsonCount(2, 'periodos')->json();
        $data = ['solicitud_token' => (string) \Illuminate\Support\Str::uuid(), 'materia_token' => OpaqueUrlToken::for('materia', $math->id), 'periodo_token' => $period(0),
            'responsable_token' => OpaqueUrlToken::for('usuario', $clerk->id), 'motivo' => 'Certificación individual de Matemáticas de Ana.'];
        $bytes = "%PDF-1.4\n% Soporte individual\n%%EOF";
        $create = fn ($payload) => $this->post($path, [...$payload, 'soporte' => UploadedFile::fake()->createWithContent('certificado.pdf', $bytes)], ['Accept' => 'application/json']);
        $this->getJson($url('1'))->assertNotFound();
        $this->getJson($url($foreignToken))->assertNotFound();
        $this->postJson($path, $data)->assertUnprocessable()->assertJsonValidationErrors('soporte');
        $create([...$data, 'motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $create([...$data, 'periodo_token' => $period(2)])->assertUnprocessable();
        $login($clerkToken);
        $create($data)->assertForbidden();
        $login($this->staffToken);
        $create($data)->assertNoContent();
        $create($data)->assertNoContent();
        $getGrant = fn () => $this->getJson($path)->assertOk()->assertJsonCount(1, 'autorizaciones')->json('autorizaciones.0');
        $grant = $getGrant();
        $apply = '/'.$grant['token'].'/registrar';
        $grade = ['version_escala' => $form['escala']['version'], 'valor' => '4.0'];
        $this->postJson($path.$apply, $grade)->assertForbidden();
        $read = fn () => collect($this->getJson($sheet)->assertOk()->json('data.resultados'))->keyBy('matricula_token');
        $this->assertSame('pendiente', $read()[$anaToken]['estado']);
        $login($clerkToken);
        $this->assertSame($bytes, $this->get($path.'/soportes/'.$grant['archivo_token'])->assertOk()->assertDownload('certificado.pdf')->streamedContent());
        $this->get($url($peerToken).'/soportes/'.$grant['archivo_token'])->assertNotFound();
        $this->postJson($url($peerToken).$apply, $grade)->assertNotFound();
        $this->postJson($path.$apply, [...$grade, 'periodo_token' => $period(1)])->assertUnprocessable();
        $this->postJson($path.$apply, [...$grade, 'valor' => '6'])->assertUnprocessable();
        $this->postJson($path.$apply, [...$grade, 'version_escala' => str_repeat('x', 64)])->assertConflict();
        $clerk->revokePermissionTo(EntryAssessments::REGISTER);
        $login($clerkToken);
        $this->postJson($path.$apply, $grade)->assertForbidden();
        $clerk->givePermissionTo(EntryAssessments::REGISTER);
        $login($clerkToken);
        $year->update(['estado' => 'cerrado']);
        $this->postJson($path.$apply, $grade)->assertUnprocessable();
        $year->update(['estado' => 'en_curso']);
        $this->postJson($path.$apply, $grade)->assertNoContent();
        $this->postJson($path.$apply, $grade)->assertNoContent();
        $this->postJson($path.$apply, [...$grade, 'valor' => '3.5'])->assertConflict();
        $used = $getGrant();
        $this->assertFalse($used['puede_registrar']);
        $this->assertSame($rector->name, $used['autorizado_por']);
        $this->assertSame($clerk->name, $used['responsable']);
        $this->assertNotNull($used['aprobado_en']);
        $login($this->staffToken);
        $this->postJson($path.'/'.$grant['token'].'/revocar', ['motivo' => 'Prueba'])->assertConflict();
        $secondTerm = [...$data, 'periodo_token' => $period(1), 'solicitud_token' => (string) \Illuminate\Support\Str::uuid()];
        $create($secondTerm)->assertNoContent();
        $second = $this->getJson($path)->assertOk()->json('autorizaciones.0.token');
        $this->postJson($path.'/'.$second.'/revocar', ['motivo' => 'El acta solo cubre el primer periodo.'])->assertNoContent();
        $create($secondTerm)->assertConflict();
        $login($clerkToken);
        $this->postJson($path.'/'.$second.'/registrar', $grade)->assertConflict();
        $login($this->staffToken);
        $rows = $read();
        $this->assertEquals(4, $rows[$anaToken]['display_value']);
        $this->assertSame('pendiente', $rows[$peerToken]['estado']);
        $ordinary($anaToken)->assertUnprocessable();
        $ordinary($peerToken)->assertUnprocessable();
        $this->assertSame('cerrado', $periods[0]->fresh()->estado);
        $this->assertSame(0, Calificacion::count());
        $this->assertSame(1, DB::table(EntryAssessments::TABLE)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('recurso', 'autorizacion_definitiva_cerrada')->where('accion', 'APPROVE')->count());
        $this->getJson($this->base.'/evaluacion/boletines/'.$anaToken)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.1.estado', 'pendiente');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', $this->getJson($path)->getContent());
        // The original 4.0 is already official. Only a fresh, scoped grant can change it.
        $case = [...$data, 'solicitud_token' => (string) \Illuminate\Support\Str::uuid(), 'rectifica_token' => $grant['token'],
            'version' => 0, 'motivo' => 'El acta de revisión corrige Matemáticas de Ana en el primer periodo.'];
        $amendment = "%PDF-1.4\n% Acta de rectificacion\n%%EOF";
        $support = fn () => UploadedFile::fake()->createWithContent('acta-rectificacion.pdf', $amendment);
        $authorize = fn ($payload) => $this->post($path, [...$payload, 'soporte' => $support()], ['Accept' => 'application/json']);
        $assessments = $this->base.'/matriculas/'.$anaToken.'/valoraciones-ingreso';
        $resultToken = DB::table(EntryAssessments::TABLE)->value('token');
        $rector->givePermissionTo(EntryAssessments::RECTIFY);
        $login($this->staffToken);
        $this->post($assessments.'/'.$resultToken.'/rectificaciones', ['valor' => '3.5', 'version' => 0, 'motivo' => $case['motivo'],
            'soporte' => $support()], ['Accept' => 'application/json'])->assertConflict();
        $this->postJson($path, $case)->assertUnprocessable()->assertJsonValidationErrors('soporte');
        $authorize([...$case, 'motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $authorize($case)->assertNotFound(); // The designated user can enter initial grades only.
        $clerk->givePermissionTo(EntryAssessments::RECTIFY);
        $authorize([...$case, 'periodo_token' => $period(1)])->assertNotFound();
        $authorize([...$case, 'version' => 1])->assertConflict();
        $authorize($case)->assertNoContent();
        $authorize([...$case, 'solicitud_token' => (string) \Illuminate\Support\Str::uuid()])->assertConflict();
        $this->assertEquals(4, $read()[$anaToken]['display_value']);
        $correction = $this->getJson($path)->assertOk()->json('autorizaciones.0');
        $this->assertTrue($correction['es_rectificacion']);
        $this->assertNotSame($grant['token'], $correction['token']);
        $register = '/'.$correction['token'].'/registrar';
        $newGrade = [...$grade, 'valor' => '3.5'];
        $this->postJson($path.$register, $newGrade)->assertForbidden();
        $clerk->revokePermissionTo(EntryAssessments::RECTIFY);
        $login($clerkToken);
        $this->postJson($path.$register, $newGrade)->assertForbidden();
        $clerk->givePermissionTo(EntryAssessments::RECTIFY);
        $login($clerkToken);
        $this->postJson($url($peerToken).$register, $newGrade)->assertNotFound();
        $this->postJson($path.$register, $grade)->assertUnprocessable();
        $this->postJson($path.$register, $newGrade)->assertNoContent();
        $this->postJson($path.$register, $newGrade)->assertNoContent();
        $this->postJson($path.$register, $grade)->assertConflict();
        $this->postJson($path.$apply, $grade)->assertNoContent(); // Delayed initial submission preserves the later revision.
        $this->assertSame($amendment, $this->get($path.'/soportes/'.$correction['archivo_token'])->assertDownload('acta-rectificacion.pdf')->streamedContent());
        $login($this->staffToken);
        $authorize($case)->assertNoContent();
        $authorize([...$case, 'solicitud_token' => (string) \Illuminate\Support\Str::uuid()])->assertConflict();
        $this->postJson($path.'/'.$correction['token'].'/revocar', ['motivo' => 'Prueba'])->assertConflict();
        $events = collect($this->getJson($path)->assertOk()->json('autorizaciones'))->keyBy('token');
        $this->assertEquals(4, $events[$grant['token']]['registrada']);
        $this->assertEquals(3.5, $events[$grant['token']]['vigente']);
        $this->assertEquals(4, $events[$correction['token']]['anterior']);
        $this->assertEquals(3.5, $events[$correction['token']]['registrada']);
        $this->assertSame($case['motivo'], $events[$correction['token']]['motivo']);
        $this->assertSame($rector->name, $events[$correction['token']]['autorizado_por']);
        $this->assertSame($clerk->name, $events[$correction['token']]['responsable']);
        $this->assertNotNull($events[$correction['token']]['aprobado_en']);
        $this->assertSame(1, DB::table('audit_logs')->where('recurso', EntryAssessments::RECTIFICATION_RESOURCE)->where('accion', 'UPDATE')->count());
        $revision = $this->getJson($assessments)->assertOk()->assertJsonCount(1, 'valoraciones.0.rectificaciones')->json('valoraciones.0.rectificaciones.0');
        $this->assertEquals(4, $revision['anterior']['valor']);
        $this->assertEquals(3.5, $revision['nueva']['valor']);
        $this->assertSame($correction['archivo_token'], $revision['archivo_token']);
        $rows = $read();
        $this->assertEquals(3.5, $rows[$anaToken]['display_value']);
        $this->assertSame('pendiente', $rows[$peerToken]['estado']);
        $ordinary($anaToken)->assertUnprocessable();
        $ordinary($peerToken)->assertUnprocessable();
        $this->assertSame('cerrado', $periods[0]->fresh()->estado);
        $this->assertSame(1, DB::table(EntryAssessments::TABLE)->count());
        $this->assertSame(0, Calificacion::count());
        $login($ana->createToken('test')->plainTextToken);
        $this->getJson($path)->assertForbidden();
    }

    public function test_individual_closed_grade_correction_links_anas_period_bulletins_and_preserves_both_pdfs(): void
    {
        [$year, $periods, $group, $math, $ana, $enrollment] = $this->thirdPeriodEnrollment(Periodo::ESTADO_ABIERTO);
        [$scale, $rector, $assignment] = $this->configureMathGrades($year, $math, $group);
        $scale->update(['desempenos' => [['nombre' => 'Bajo', 'desde' => '0.0', 'hasta' => '2.9'],
            ['nombre' => 'Alcanzado', 'desde' => '3.0', 'hasta' => '5.0']]]);
        $rector->givePermissionTo([EntryAssessments::REGISTER, EntryAssessments::RECTIFY, 'boletines.observaciones.gestionar',
            'boletines.emisiones.preparar', 'boletines.emisiones.ver', 'boletines.emisiones.publicar']);
        $student = OpaqueUrlToken::for('matricula', $enrollment->id);
        $period = $periods[0];
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $selection = ['contexto' => 'periodo', 'periodo_token' => $periodToken];
        $this->putJson($this->base.'/boletines/observaciones/'.$student, [...$selection, 'version' => 0,
            'contenido' => 'Seguimiento académico de Ana del primer periodo.'])->assertOk();
        $activity = $this->localMathActivity($assignment, $period);
        $period->update(['estado' => Periodo::ESTADO_CERRADO]);
        $this->travel(1)->minutes();
        $closedAt = $period->fresh()->updated_at;
        $template = $this->postJson($this->base.'/boletines/predefinidos/periodo-numerico',
            ['accent' => '#183C67', 'size' => 12])->assertCreated()->json('data.token');
        $archive = $this->base.'/boletines/emisiones/';
        $emit = fn () => $this->postJson($archive.$student.'/emitir', [...$selection, 'plantilla_token' => $template])->assertOk();
        $download = fn ($token) => $this->get($archive.'documentos/'.$token.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $publish = fn ($token) => $this->postJson($archive.'documentos/'.$token.'/publicar')->assertOk();
        $grants = $this->base.'/matriculas/'.$student.'/autorizaciones-cierre';
        $form = $this->getJson($grants)->assertOk()->json();
        $authorize = fn ($reason, $file, $target = []) => $this->authorizeMathGrade($grants, $periodToken, $math, $rector, $reason, $file, $target);
        $record = fn ($grant, $value) => $this->postJson($grants.'/'.$grant.'/registrar',
            ['valor' => $value, 'version_escala' => $form['escala']['version']])->assertNoContent();

        $initial = $authorize('Registro individual de Matemáticas.', 'registro.pdf');
        $record($initial, '4.0');
        $first = $emit()->assertJsonPath('data.revision', 1)->assertJsonPath('data.rectificacion', null)->json('data.token');
        $publish($first);
        $original = BoletinEmision::sole();
        $originalSnapshot = $original->snapshot;
        $originalPdf = $download($first);
        $this->assertSame('4.0', $originalSnapshot['asignaturas'][0]['resultado']['display_value']);
        $this->assertSame($original->pdf_sha256, hash('sha256', $originalPdf));

        $correction = $authorize('El acta de revisión ajusta la definitiva de Ana.', 'rectificacion.pdf',
            ['rectifica_token' => $initial, 'version' => 0]);
        $emit()->assertJsonPath('data.token', $first);
        $record($correction, '3.5');
        $this->assertSame(1, BoletinEmision::count());
        $second = $emit()->assertJsonPath('data.revision', 2)->assertJsonPath('data.rectificacion.boletin_anterior_token', $first)
            ->assertJsonPath('data.rectificacion.revision_anterior', 1)->assertJsonCount(1, 'data.rectificacion.cambios')
            ->assertJsonPath('data.rectificacion.cambios.0', ['materia' => 'Matemáticas', 'periodo' => 'Primero',
                'anterior' => '4.0', 'vigente' => '3.5'])->json('data.token');
        $revised = BoletinEmision::where('revision', 2)->sole();
        $this->assertSame($original->id, $revised->rectifica_id);
        $this->assertSame('3.5', $revised->snapshot['asignaturas'][0]['resultado']['display_value']);
        $this->assertSame('publicado', $original->fresh()->estado);
        $anaLogin = $ana->createToken('test')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($anaLogin)->getJson($archive.$student)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.token', $first);
        $this->get($archive.'documentos/'.$second.'/pdf')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
        $publish($second);
        $emit()->assertJsonPath('data.token', $second);
        $this->getJson($archive.$student)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.estado', 'publicado')->assertJsonPath('data.0.rectificacion.boletin_anterior_token', $first)
            ->assertJsonPath('data.1.token', $first)->assertJsonPath('data.1.estado', 'sustituido');
        $this->assertSame($originalSnapshot, $original->fresh()->snapshot);
        $this->assertSame($originalPdf, $download($first));
        $revisedPdf = $download($second);
        $this->assertSame($revised->pdf_sha256, hash('sha256', $revisedPdf));
        $this->assertNotSame($originalPdf, $revisedPdf);
        if ($reader = (new \Symfony\Component\Process\ExecutableFinder)->find('pdftotext')) {
            $read = fn ($bytes) => (new \Symfony\Component\Process\Process([$reader, '-layout', '-enc', 'UTF-8', '-', '-']))
                ->setInput($bytes)->mustRun()->getOutput();
            $this->assertMatchesRegularExpression('/Matemáticas\h+4[.,]0\b/u', $read($originalPdf));
            $this->assertMatchesRegularExpression('/Matemáticas\h+3[.,]5\b/u', $read($revisedPdf));
        }
        $sheet = $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $assignment->id).'/'.$periodToken;
        $this->putJson($sheet, ['notas' => [['matricula_token' => $student, 'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id),
            'valor' => '3.5', 'version' => 0]]])->assertUnprocessable();
        $this->assertTrue($closedAt->equalTo($period->fresh()->updated_at));
        $this->assertSame(Periodo::ESTADO_CERRADO, $period->fresh()->estado);
        app('auth')->forgetGuards();
        $this->withToken($anaLogin)->getJson($archive.$student)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.token', $second)->assertJsonPath('data.0.rectificacion.revision_anterior', 1);
        $this->assertSame($revisedPdf, $download($second));
        $this->get($archive.'documentos/'.$first.'/pdf')->assertForbidden();
    }

    private function authorizeMathGrade(string $grants, string $periodToken, Materia $math, User $responsible,
        string $reason, string $file, array $target = []): string
    {
        $this->post($grants, [...$target, 'solicitud_token' => (string) \Illuminate\Support\Str::uuid(),
            'materia_token' => OpaqueUrlToken::for('materia', $math->id), 'periodo_token' => $periodToken,
            'responsable_token' => OpaqueUrlToken::for('usuario', $responsible->id), 'motivo' => $reason,
            'soporte' => UploadedFile::fake()->createWithContent($file, "%PDF-1.4\n% ".$reason."\n%%EOF")],
            ['Accept' => 'application/json'])->assertNoContent();
        return $this->getJson($grants)->assertOk()->json('autorizaciones.0.token');
    }

    private function anaWithMathSourceChoice(): array
    {
        [$year, $periods, $group, $math, , $enrollment] = $this->thirdPeriodEnrollment();
        [, $rector, $assignment] = $this->configureMathGrades($year, $math, $group);
        $rector->givePermissionTo(['boletines.observaciones.gestionar', 'boletines.emisiones.preparar',
            'boletines.emisiones.ver', 'boletines.emisiones.publicar']);
        $activity = $this->localMathActivity($assignment, $periods[0]);
        Calificacion::create(['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id,
            'valor' => '1.0', 'updated_by' => $rector->id]);
        $student = OpaqueUrlToken::for('matricula', $enrollment->id);
        $selection = ['contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $periods[0]->id)];
        $periods[0]->update(['estado' => 'abierto']);
        $this->putJson($this->base.'/boletines/observaciones/'.$student, [...$selection, 'version' => 0,
            'contenido' => 'Informe de Matemáticas del primer periodo.'])->assertOk();
        $periods[0]->update(['estado' => 'cerrado']);
        $grades = $this->base.'/matriculas/'.$student.'/definitivas-externas';
        $this->post($grades, ['fecha_ingreso' => $periods[2]->fecha_inicio->toDateString(),
            'colegio_origen' => 'Colegio anterior', 'grado_origen' => 'Primero', 'escala_origen' => '0 a 100',
            'certificado' => UploadedFile::fake()->createWithContent('certificado.pdf', "%PDF-1.4\n% Matemáticas\n%%EOF"),
            'resultados' => [['materia_origen' => 'Matemáticas', 'periodo_origen' => 'Primero', 'valor_original' => '80',
                'desde' => $periods[0]->fecha_inicio->toDateString(), 'hasta' => $periods[0]->fecha_fin->toDateString()],
                ['materia_origen' => 'Matemáticas', 'periodo_origen' => 'Segundo', 'valor_original' => '90',
                'desde' => $periods[1]->fecha_inicio->toDateString(), 'hasta' => $periods[1]->fecha_fin->toDateString()]]],
            ['Accept' => 'application/json'])->assertNoContent();
        $details = $this->getJson($grades)->assertOk()->json();
        $token = $details['certificados'][0]['resultados'][0]['token'];
        foreach (['4.0', '4.5'] as $index => $value) {
            $result = $details['certificados'][0]['resultados'][$index];
            $this->postJson($grades.'/'.$result['token'].'/aprobar', ['materia_token' => OpaqueUrlToken::for('materia', $math->id),
                'periodo_token' => OpaqueUrlToken::for('periodo', $periods[$index]->id), 'version_escala' => $details['escala']['version'],
                'valor' => $value, 'criterio' => 'Conversión proporcional de 100 a 5.'])->assertNoContent();
        }
        $fingerprint = $this->getJson($grades)->assertOk()->json('certificados.0.resultados.0.eleccion.huella');
        $choose = fn ($source, $version, $reason) => $this->postJson($grades.'/'.$token.'/eleccion',
            ['fuente' => $source, 'version' => $version, 'motivo' => $reason, 'huella' => $fingerprint])->assertNoContent();
        return compact('year', 'periods', 'assignment', 'rector', 'enrollment', 'student', 'selection', 'token', 'choose');
    }

    public function test_third_period_source_choice_rectifies_anas_issued_math_bulletin(): void
    {
        ['enrollment' => $enrollment, 'student' => $student, 'selection' => $selection, 'token' => $token,
            'choose' => $choose] = $this->anaWithMathSourceChoice();
        $archive = $this->base.'/boletines/emisiones/';
        $emit = fn () => $this->postJson($archive.$student.'/emitir', $selection)->assertOk();
        $pdf = fn ($id) => $this->get($archive.'documentos/'.$id.'/pdf')->assertOk()->getContent();
        $publish = fn ($id) => $this->postJson($archive.'documentos/'.$id.'/publicar')->assertOk();

        $choose('colegio', 0, 'Se toma la planilla local.');
        $first = $emit()->assertJsonPath('data.revision', 1)->assertJsonPath('data.rectificacion', null)->json('data.token');
        $publish($first);
        $original = BoletinEmision::sole();
        $snapshot = $original->snapshot;
        $this->assertSame('1.0', $snapshot['asignaturas'][0]['resultado']['display_value']);
        $originalPdf = $pdf($first);
        $choose('certificado', 1, 'El certificado cubre el periodo completo.');
        $this->assertSame(1, BoletinEmision::count());
        $second = $emit()->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.rectificacion.boletin_anterior_token', $first)
            ->assertJsonPath('data.rectificacion.revision_anterior', 1)
            ->assertJsonCount(1, 'data.rectificacion.cambios')
            ->assertJsonPath('data.rectificacion.cambios.0', ['materia' => 'Matemáticas', 'periodo' => 'Primero',
                'anterior' => '1.0', 'vigente' => '4.0', 'fuente_anterior' => 'colegio', 'fuente_vigente' => 'certificado'])->json('data.token');
        $corrected = BoletinEmision::where('revision', 2)->sole();
        $this->assertSame($original->id, $corrected->rectifica_id);
        $this->assertSame('4.0', $corrected->snapshot['asignaturas'][0]['resultado']['display_value']);
        $change = $corrected->snapshot['rectificacion']['cambios'][0];
        $this->assertSame($token, $change['eleccion_resultado_token']);
        $this->assertSame(2, $change['eleccion_version']);
        // A PDF issued by the previous release has the school grade but no choice token.
        $legacy = $snapshot;
        unset($legacy['asignaturas'][0]['eleccion_definitiva']);
        $this->assertEquals([$change], app(\App\Services\BulletinIssueService::class)->correctedGrades($enrollment, $legacy, $corrected->snapshot));
        $publish($second);
        $emit()->assertJsonPath('data.token', $second);
        $choose('certificado', 2, 'Se ratifica la misma fuente y la misma nota.');
        $emit()->assertJsonPath('data.token', $second);
        $this->assertSame(2, BoletinEmision::count());
        $this->getJson($archive.$student)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rectificacion.boletin_anterior_token', $first)->assertJsonPath('data.1.estado', 'sustituido');
        $this->assertSame($snapshot, $original->fresh()->snapshot);
        $this->assertSame($originalPdf, $pdf($first));
        $correctedPdf = $pdf($second);
        $this->assertNotSame($originalPdf, $correctedPdf);
        $this->assertSame($corrected->pdf_sha256, hash('sha256', $correctedPdf));
        if ($reader = (new \Symfony\Component\Process\ExecutableFinder)->find('pdftotext')) {
            $read = fn ($bytes) => (new \Symfony\Component\Process\Process([$reader, '-layout', '-enc', 'UTF-8', '-', '-']))
                ->setInput($bytes)->mustRun()->getOutput();
            $this->assertMatchesRegularExpression('/Matemáticas\h+1[.,]0/u', $read($originalPdf));
            $rendered = $read($correctedPdf);
            $this->assertMatchesRegularExpression('/Matemáticas\s+4[.,]0\b/u', $rendered);
            $this->assertStringContainsString('Rectifica la revisión 1', $rendered);
        }
    }

    public function test_third_period_final_bulletin_links_anas_recalculated_annual_math_grade(): void
    {
        ['year' => $year, 'periods' => $periods, 'assignment' => $assignment, 'rector' => $rector, 'enrollment' => $enrollment,
            'student' => $student, 'token' => $resultToken, 'choose' => $choose] = $this->anaWithMathSourceChoice();
        $choose('colegio', 0, 'Se toma la planilla local.');
        foreach ([2 => '3.5', 3 => '4.8'] as $index => $value) {
            $activity = $this->localMathActivity($assignment, $periods[$index]);
            Calificacion::create(['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id,
                'valor' => $value, 'updated_by' => $rector->id]);
        }
        Periodo::where('ano_lectivo_id', $year->id)->update(['estado' => 'cerrado']);
        $target = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $enrollment->grupo->grado->nivel_id,
            'nombre' => 'Segundo', 'estado' => 'activo']);
        $yearPath = $this->base.'/anos-lectivos/'.OpaqueUrlToken::for('ano-lectivo', $year->id);
        $this->putJson($yearPath.'/promociones/politica', ['max_reprobadas' => 0, 'materias_obligatorias' => [], 'version' => 0])->assertOk();
        $promotion = fn () => $this->getJson($yearPath.'/promociones')->assertOk()->json('data.0');
        $approve = fn ($proposal) => $this->putJson($yearPath.'/promociones/'.$student, [
            'huella' => $proposal['propuesta']['huella'], 'resultado' => 'promovido',
            'grado_destino_token' => OpaqueUrlToken::for('grado', $target->id), 'motivo' => 'Resultados anuales revisados.',
            'version' => $proposal['decision']['version'] ?? 0])->assertOk();
        $close = fn () => $this->postJson($yearPath.'/cerrar', ['confirmar_promociones' => true]);
        $approve($promotion());
        $close()->assertOk();
        $archive = $this->base.'/boletines/emisiones/';
        $emit = fn () => $this->postJson($archive.$student.'/emitir', ['contexto' => 'final']);
        $pdf = fn ($id) => $this->get($archive.'documentos/'.$id.'/pdf')->assertOk()->getContent();
        $publish = fn ($id) => $this->postJson($archive.'documentos/'.$id.'/publicar')->assertOk();
        $first = $emit()->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.rectificacion', null)->json('data.token');
        $publish($first);
        $original = BoletinEmision::sole();
        $snapshot = $original->snapshot;
        $this->assertSame('3.4', $snapshot['asignaturas'][0]['resultado']['display_value']);
        $originalPdf = $pdf($first);

        $grades = $this->base.'/matriculas/'.$student.'/definitivas-externas';
        $choiceHash = $this->getJson($grades)->assertOk()->json('certificados.0.resultados.0.eleccion.huella');
        $this->postJson($grades.'/'.$resultToken.'/eleccion', ['fuente' => 'certificado', 'version' => 1,
            'motivo' => 'Se solicita la corrección.', 'huella' => $choiceHash])->assertUnprocessable();
        $this->reopenBulletinYearAsPlatform($yearPath);
        $choose('certificado', 1, 'El certificado cubre Matemáticas del primer periodo.');
        $math = $this->getJson($this->base.'/evaluacion/boletines/'.$student)->assertOk()->json('data.asignaturas.0');
        $this->assertSame('4.2', $math['anual']['display_value']);
        $this->assertCount(4, $math['anual']['trace']['inputs']);
        $this->assertSame(['4','4.5','3.5','4.8'], array_map(
            fn ($term) => \App\Support\AcademicDecimal::normalize($term['display_value']), $math['periodos']));
        $emit()->assertUnprocessable();
        $close()->assertUnprocessable();
        $decision = $promotion();
        $this->assertFalse($decision['decision']['vigente']);
        $approve($decision);
        $close()->assertOk();
        $second = $emit()->assertOk()->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.rectificacion.boletin_anterior_token', $first)
            ->assertJsonCount(1, 'data.rectificacion.cambios')
            ->assertJsonPath('data.rectificacion.cambios.0', ['materia' => 'Matemáticas', 'periodo' => 'Primero',
                'anterior' => '1.0', 'vigente' => '4.0', 'fuente_anterior' => 'colegio', 'fuente_vigente' => 'certificado',
                'anual_anterior' => '3.4', 'anual_vigente' => '4.2'])->json('data.token');
        $revision = BoletinEmision::where('revision', 2)->sole();
        $this->assertSame($original->id, $revision->rectifica_id);
        $this->assertSame($resultToken, $revision->snapshot['rectificacion']['cambios'][0]['eleccion_resultado_token']);
        $legacy = $snapshot;
        unset($legacy['asignaturas'][0]['materia_ref'], $legacy['asignaturas'][0]['definitivas_periodo']);
        $this->assertEquals($revision->snapshot['rectificacion']['cambios'],
            app(\App\Services\BulletinIssueService::class)->correctedGrades($enrollment, $legacy, $revision->snapshot));
        $publish($second);
        $emit()->assertOk()->assertJsonPath('data.token', $second);
        $this->getJson($archive.$student)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rectificacion.boletin_anterior_token', $first)->assertJsonPath('data.1.estado', 'sustituido');
        $this->assertSame($snapshot, $original->fresh()->snapshot);
        $this->assertSame($originalPdf, $pdf($first));
        $newPdf = $pdf($second);
        $this->assertNotSame($originalPdf, $newPdf);
        $this->assertSame($revision->pdf_sha256, hash('sha256', $newPdf));
        if ($reader = (new \Symfony\Component\Process\ExecutableFinder)->find('pdftotext')) {
            $read = fn ($bytes) => (new \Symfony\Component\Process\Process([$reader, '-layout', '-enc', 'UTF-8', '-', '-']))
                ->setInput($bytes)->mustRun()->getOutput();
            $this->assertMatchesRegularExpression('/Matemáticas\s+3[.,]4/u', $read($originalPdf));
            $rendered = $read($newPdf);
            // With two certificate lines, the PDF vertically centers the annual grade in the right column.
            $this->assertMatchesRegularExpression('/Matemáticas\s+(?:Equivalencia · Primero · 4[.,]0\s+)?4[.,]2\b/u', $rendered);
            $this->assertStringContainsString('Equivalencia · Segundo · 4.5', $rendered);
            $this->assertStringContainsString('Rectifica la revisión 1', $rendered);
        }
    }

    private function reopenBulletinYearAsPlatform(string $yearPath): void
    {
        $this->postJson($yearPath.'/reabrir')->assertForbidden();
        $shadow = User::create(['name' => 'Plataforma', 'email' => User::PLATFORM_SUPERADMIN_EMAIL, 'password' => 'Testing123!',
            'role' => 'rector', 'status' => 'active', 'must_change_password' => false]);
        $shadow->assignRole('rector');
        $session = $shadow->createToken('impersonation', ['*'], now()->addMinutes(15));
        $admin = User::on(config('tenancy.database.central_connection'))->create(['name' => 'Administradora',
            'email' => 'admin@example.test', 'role' => 'superadmin', 'status' => 'active', 'password' => 'Testing123!']);
        \App\Models\Impersonation::create(['tenant_id' => $this->school->id, 'superadmin_id' => $admin->id,
            'superadmin_email' => $admin->email, 'started_at' => now(), 'expires_at' => now()->addMinutes(15),
            'token_id' => $session->accessToken->id]);
        app('auth')->forgetGuards();
        $this->withToken($session->plainTextToken)->postJson($yearPath.'/reabrir')->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
    }

    public function test_individual_closed_grade_correction_recalculates_anas_final_bulletin(): void
    {
        [$year, $periods, $group, $math, $ana, $enrollment] = $this->thirdPeriodEnrollment();
        [$scale, $rector, $assignment] = $this->configureMathGrades($year, $math, $group);
        $scale->update(['desempenos' => [['nombre' => 'Bajo', 'desde' => '0.0', 'hasta' => '2.9'],
            ['nombre' => 'Alcanzado', 'desde' => '3.0', 'hasta' => '5.0']]]);
        $rector->givePermissionTo([EntryAssessments::REGISTER, EntryAssessments::RECTIFY,
            'boletines.emisiones.preparar', 'boletines.emisiones.ver', 'boletines.emisiones.publicar']);
        $student = OpaqueUrlToken::for('matricula', $enrollment->id);
        $term = OpaqueUrlToken::for('periodo', $periods[0]->id);
        $grants = $this->base.'/matriculas/'.$student.'/autorizaciones-cierre';
        $gradingScale = $this->getJson($grants)->assertOk()->json('escala.version');
        $enter = fn ($grant, $grade) => $this->postJson($grants.'/'.$grant.'/registrar', ['version_escala' => $gradingScale, 'valor' => $grade])->assertNoContent();
        $initial = $this->authorizeMathGrade($grants, $term, $math, $rector, 'Registro de Matemáticas de Ana.', 'registro.pdf');
        $enter($initial, '4.0');
        $external = $this->base.'/matriculas/'.$student.'/definitivas-externas';
        $this->post($external, ['fecha_ingreso' => $periods[2]->fecha_inicio->toDateString(), 'colegio_origen' => 'Colegio anterior',
            'grado_origen' => 'Primero', 'escala_origen' => '0 a 100', 'certificado' => UploadedFile::fake()->createWithContent('segundo.pdf', "%PDF-1.4\n% Segundo\n%%EOF"),
            'resultados' => [['materia_origen' => 'Matemáticas', 'periodo_origen' => 'Segundo', 'valor_original' => '90',
                'desde' => $periods[1]->fecha_inicio->toDateString(), 'hasta' => $periods[1]->fecha_fin->toDateString()]]],
            ['Accept' => 'application/json'])->assertNoContent();
        $certified = $this->getJson($external)->assertOk()->json('certificados.0.resultados.0.token');
        $this->postJson($external.'/'.$certified.'/aprobar', ['materia_token' => OpaqueUrlToken::for('materia', $math->id),
            'periodo_token' => OpaqueUrlToken::for('periodo', $periods[1]->id), 'version_escala' => $gradingScale,
            'valor' => '4.5', 'criterio' => 'Equivalencia del certificado de segundo periodo.'])->assertNoContent();
        foreach ([2 => '3.5', 3 => '4.8'] as $index => $value) {
            Calificacion::create(['matricula_id' => $enrollment->id, 'actividad_id' => $this->localMathActivity($assignment, $periods[$index])->id,
                'valor' => $value, 'updated_by' => $rector->id]);
        }
        Periodo::where('ano_lectivo_id', $year->id)->where('estado', '!=', 'cerrado')->update(['estado' => 'cerrado']);
        $destination = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $group->grado->nivel_id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $yearPath = $this->base.'/anos-lectivos/'.OpaqueUrlToken::for('ano-lectivo', $year->id);
        $this->putJson($yearPath.'/promociones/politica', ['max_reprobadas' => 0, 'materias_obligatorias' => [], 'version' => 0])->assertOk();
        $review = fn () => $this->getJson($yearPath.'/promociones')->assertOk()->json('data.0');
        $approve = fn ($review) => $this->putJson($yearPath.'/promociones/'.$student, ['huella' => $review['propuesta']['huella'],
            'resultado' => 'promovido', 'grado_destino_token' => OpaqueUrlToken::for('grado', $destination->id),
            'motivo' => 'Revisión anual de Matemáticas.', 'version' => $review['decision']['version'] ?? 0])->assertOk();
        $close = fn () => $this->postJson($yearPath.'/cerrar', ['confirmar_promociones' => true]);
        $approve($review());
        $close()->assertOk();
        $template = $this->postJson($this->base.'/boletines/predefinidos/final-numerico',
            ['accent' => '#183C67', 'size' => 12])->assertCreated()->json('data.token');
        $archive = $this->base.'/boletines/emisiones/';
        $emit = fn () => $this->postJson($archive.$student.'/emitir', ['contexto' => 'final', 'plantilla_token' => $template]);
        $pdf = fn ($token) => $this->get($archive.'documentos/'.$token.'/pdf')->assertOk()->getContent();
        $publish = fn ($token) => $this->postJson($archive.'documentos/'.$token.'/publicar')->assertOk();
        $first = $emit()->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.rectificacion', null)->json('data.token');
        $publish($first);
        $original = BoletinEmision::sole();
        $snapshot = $original->snapshot;
        $this->assertSame('4.2', $snapshot['asignaturas'][0]['resultado']['display_value']);
        $originalPdf = $pdf($first);

        $this->reopenBulletinYearAsPlatform($yearPath);
        $this->assertSame(4, Periodo::where('ano_lectivo_id', $year->id)->where('estado', 'cerrado')->count());
        $amendment = $this->authorizeMathGrade($grants, $term, $math, $rector, 'Corrección individual de Matemáticas de primero.', 'rectificacion.pdf',
            ['rectifica_token' => $initial, 'version' => 0]);
        $enter($amendment, '3.5');
        $report = $this->getJson($this->base.'/evaluacion/boletines/'.$student)->assertOk()->json('data.asignaturas.0');
        $this->assertSame(['3.5','4.5','3.5','4.8'], array_column($report['periodos'], 'display_value'));
        $this->assertSame('externa', $report['periodos'][1]['origen']);
        $this->assertSame('4.1', $report['anual']['display_value']);
        $this->assertEqualsCanonicalizing($periods->map(fn ($p) => 'periodo:'.OpaqueUrlToken::for('periodo', $p->id))->all(),
            array_column($report['anual']['trace']['inputs'], 'reference'));
        $emit()->assertUnprocessable();
        $close()->assertUnprocessable();
        $decision = $review();
        $this->assertFalse($decision['decision']['vigente']);
        $approve($decision);
        $close()->assertOk();
        $second = $emit()->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.rectificacion.boletin_anterior_token', $first)
            ->assertJsonPath('data.rectificacion.revision_anterior', 1)->assertJsonCount(1, 'data.rectificacion.cambios')
            ->assertJsonPath('data.rectificacion.cambios.0', ['materia' => 'Matemáticas', 'periodo' => 'Primero', 'anterior' => '4.0', 'vigente' => '3.5',
                'anual_anterior' => '4.2', 'anual_vigente' => '4.1'])->json('data.token');
        $revised = BoletinEmision::where('revision', 2)->sole();
        $this->assertSame($original->id, $revised->rectifica_id);
        $this->assertSame('4.1', $revised->snapshot['asignaturas'][0]['resultado']['display_value']);
        $this->assertSame(DB::table(EntryAssessments::TABLE)->sole()->token, $revised->snapshot['rectificacion']['cambios'][0]['valoracion_token']);
        $publish($second);
        $emit()->assertOk()->assertJsonPath('data.token', $second);
        $this->getJson($archive.$student)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.estado', 'sustituido');
        $this->assertSame($snapshot, $original->fresh()->snapshot);
        $this->assertSame($originalPdf, $pdf($first));
        $revisedPdf = $pdf($second);
        $this->assertSame($revised->pdf_sha256, hash('sha256', $revisedPdf));
        if ($reader = (new \Symfony\Component\Process\ExecutableFinder)->find('pdftotext')) {
            $read = fn ($bytes) => (new \Symfony\Component\Process\Process([$reader, '-layout', '-enc', 'UTF-8', '-', '-']))
                ->setInput($bytes)->mustRun()->getOutput();
            $this->assertMatchesRegularExpression('/Matemáticas\h+4[.,]0\h+4[.,]5\h+3[.,]5\h+4[.,]8\h+4[.,]2\b/u', $read($originalPdf));
            $this->assertMatchesRegularExpression('/Matemáticas\h+3[.,]5\h+4[.,]5\h+3[.,]5\h+4[.,]8\h+4[.,]1\b/u', $read($revisedPdf));
        }
        app('auth')->forgetGuards();
        $this->withToken($ana->createToken('test')->plainTextToken)->getJson($archive.$student)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.token', $second);
        $this->assertSame($revisedPdf, $pdf($second));
    }

    public function test_third_period_student_awaits_certificate_until_each_math_equivalence_is_approved(): void
    {
        [$year,$periods,$group,$math,$student,$enrollment] = $this->thirdPeriodEnrollment();
        [, $rector,$assignment] = $this->configureMathGrades($year, $math, $group);
        $studentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $path = $this->base.'/matriculas/'.$studentToken.'/definitivas-externas';
        $track = $path.'/pendientes';
        $report = $this->base.'/evaluacion/boletines/'.$studentToken;
        $period = fn ($i) => OpaqueUrlToken::for('periodo', $periods[$i]->id);
        $sheet = fn ($i) => $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $assignment->id).'/'.$period($i);
        $this->travelTo($periods[2]->fecha_inicio->copy()->addHours(12));
        $arrival = $periods[2]->fecha_inicio->toDateString();
        $request = ['materia_token' => OpaqueUrlToken::for('materia', $math->id), 'periodos' => [$period(0),$period(1)],
            'fecha_ingreso' => $arrival, 'fecha_seguimiento' => $periods[2]->fecha_inicio->copy()->addWeeks(2)->toDateString(),
            'responsable_token' => OpaqueUrlToken::for('usuario', $rector->id)];
        $this->postJson($track, [...$request,'periodos' => [$period(0), $period(2)]])->assertUnprocessable();
        $this->postJson($track, [...$request,'responsable_token' => OpaqueUrlToken::for('usuario', $student->id)])->assertNotFound();
        $this->postJson($track, [...$request,'periodos' => ['1']])->assertNotFound();
        $foreign = app(\App\Services\TenantProvisioner::class)->provision(['name' => 'Otro colegio','slug' => 'certificado-tramite',
            'plan' => 'premium','rector_email' => 'rector@tramite.test'])['tenant'];
        $this->postJson($track, [...$request,'periodos' => [$foreign->run(fn () => $period(0))]])->assertNotFound();
        $this->getJson($path)->assertJsonCount(0, 'pendientes');
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->postJson($track, $request)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($track, $request)->assertNoContent();
        $this->postJson($track, $request)->assertNoContent();
        $info = $this->getJson($path)->assertOk()->assertJsonCount(2,'pendientes')->assertJsonCount(0,'certificados')
            ->assertJsonPath('pendientes.0.responsable',$rector->name)->assertJsonPath('pendientes.1.fecha_seguimiento',$request['fecha_seguimiento'])->json();
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($info));
        $grade = fn ($i, $value) => ['notas' => [['matricula_token' => $studentToken, 'version' => 0, 'valor' => $value,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $this->localMathActivity($assignment, $periods[$i])->id)]]];
        foreach ([0,1] as $i) {
            $this->getJson($sheet($i))->assertOk()->assertJsonPath('data.resultados.0.origen','pendiente_certificado');
        }
        $periods[0]->update(['estado' => 'abierto']);
        $this->putJson($sheet(0), $grade(0,'2'))->assertUnprocessable();
        $periods[0]->update(['estado' => 'cerrado']);
        $this->putJson($sheet(2), $grade(2,'3.5'))->assertOk();
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.estado','pendiente')
            ->assertJsonPath('data.asignaturas.0.periodos.0.certificado_recibido',false)
            ->assertJsonPath('data.asignaturas.0.periodos.1.estado','pendiente')->assertJsonPath('data.asignaturas.0.periodos.2.display_value','3.5')
            ->assertJsonPath('data.asignaturas.0.anual.estado','pendiente');
        $certificate = ['fecha_ingreso' => $arrival,'colegio_origen' => 'Colegio anterior','grado_origen' => 'Primero','escala_origen' => '0 a 100'];
        foreach (['80','90'] as $i => $value) {
            $certificate['resultados'][] = ['materia_origen' => 'Matemáticas','periodo_origen' => $periods[$i]->nombre,
                'desde' => $periods[$i]->fecha_inicio->toDateString(), 'hasta' => $periods[$i]->fecha_fin->toDateString(),
                'valor_original' => $value, 'pendiente_token' => $info['pendientes'][$i]['token']];
        }
        $submit = fn ($data) => $this->post($path, [...$data,'certificado' => UploadedFile::fake()->createWithContent('matematicas.pdf', "%PDF-1.4\n% Matemáticas\n%%EOF")], ['Accept' => 'application/json']);
        $invalid = $certificate;
        $invalid['resultados'][1]['pendiente_token'] = $info['pendientes'][0]['token'];
        $submit($invalid)->assertConflict();
        $invalid['resultados'][1]['pendiente_token'] = str_repeat('x',24);
        $submit($invalid)->assertNotFound();
        $this->assertCount(0, Storage::disk('tenant')->allFiles());
        $this->travelTo($periods[2]->fecha_inicio->copy()->addWeeks(2));
        $submit($certificate)->assertNoContent();
        $submit($certificate)->assertNoContent();
        $this->assertCount(1, Storage::disk('tenant')->allFiles());
        $received = $this->getJson($path)->assertOk()->assertJsonCount(1,'certificados')->assertJsonCount(2,'certificados.0.resultados')->json();
        $resultPath = fn ($i) => $path.'/'.$received['certificados'][0]['resultados'][$i]['token'].'/aprobar';
        $this->getJson($report)->assertJsonPath('data.asignaturas.0.periodos.0.origen','pendiente_certificado')
            ->assertJsonPath('data.asignaturas.0.periodos.1.origen','pendiente_certificado')->assertJsonPath('data.asignaturas.0.periodos.1.certificado_recibido',true);
        $decision = ['materia_token' => $request['materia_token'],'periodo_token' => $period(0),'valor' => '4',
            'version_escala' => $received['escala']['version'],'criterio' => 'Revisión del certificado de Matemáticas.'];
        $this->postJson($resultPath(1), $decision)->assertConflict();
        $this->postJson($resultPath(0), $decision)->assertNoContent();
        $this->postJson($resultPath(0), $decision)->assertNoContent();
        $this->getJson($report)->assertJsonPath('data.asignaturas.0.periodos.0.display_value','4')
            ->assertJsonPath('data.asignaturas.0.periodos.1.origen','pendiente_certificado')->assertJsonPath('data.asignaturas.0.anual.estado','pendiente');
        $this->getJson($path)->assertJsonPath('pendientes.0.aprobado_por',$rector->name)->assertJsonPath('pendientes.1.aprobado_en',null);
        $this->postJson($resultPath(1), [...$decision,'periodo_token' => $period(1),'valor' => '4.5'])->assertNoContent();
        $periods[3]->update(['estado' => 'abierto']);
        $this->putJson($sheet(3), $grade(3,'4.8'))->assertOk();
        $subject = $this->getJson($report)->assertJsonCount(4,'data.asignaturas.0.anual.trace.inputs')
            ->assertJsonPath('data.asignaturas.0.anual.display_value','4.2')->json('data.asignaturas.0');
        $this->assertSame(['4','4.5','3.5','4.8'], array_column($subject['periodos'],'display_value'));
        $this->assertCount(2, Calificacion::where('matricula_id', $enrollment->id)->get());
        $this->assertSame(2, DB::table('audit_logs')->where('recurso','matricula_certificado_pendiente')->count());
        $this->getJson($path)->assertJsonPath('pendientes.1.aprobado_por',$rector->name);

        $this->verifyChemistryWithoutEquivalence($enrollment, $rector, $periods, $request, $received);
        $selection = ['contexto' => 'periodo', 'periodo_token' => $period(1)];
        $rector->givePermissionTo(['boletines.observaciones.gestionar','boletines.emisiones.preparar','boletines.emisiones.ver']);
        $periods[1]->update(['estado' => 'abierto']);
        $this->putJson($this->base.'/boletines/observaciones/'.$studentToken, [...$selection, 'version' => 0, 'contenido' => 'Segundo periodo revisado.'])->assertOk();
        $periods[1]->update(['estado' => 'cerrado']);
        $issuePath = $this->base.'/boletines/emisiones/';
        $originalToken = $this->postJson($issuePath.$studentToken.'/emitir', $selection)->assertOk()
            ->assertJsonPath('data.rectificacion', null)->json('data.token');
        $pdfUrl = fn ($token) => $issuePath.'documentos/'.$token.'/pdf';
        $originalPdf = $this->get($pdfUrl($originalToken))->assertOk()->getContent();
        $rector->givePermissionTo('boletines.emisiones.publicar');
        $this->postJson($issuePath.'documentos/'.$originalToken.'/publicar')->assertOk()->assertJsonPath('data.estado', 'publicado');
        $issued = BoletinEmision::sole();
        $issuedMath = collect($issued->snapshot['asignaturas'])->firstWhere('nombre','Matemáticas');
        $this->assertSame('4.5', $issuedMath['resultado']['display_value']);
        $foreignEnrollment = $foreign->run(fn () => OpaqueUrlToken::for('matricula', $enrollment->id));
        $this->verifyMathRectification($enrollment, $received['certificados'][0]['resultados'][1]['token'], $sheet(1), $report, $foreignEnrollment);
        $this->assertSame($issued->snapshot, $issued->fresh()->snapshot);
        $this->assertSame(1, BoletinEmision::count());
        $corrected = $this->postJson($issuePath.$studentToken.'/emitir', $selection)->assertOk()
            ->assertJsonPath('data.revision', 2)->assertJsonPath('data.estado', 'aprobado')
            ->assertJsonPath('data.rectificacion.revision_anterior', 1)->assertJsonPath('data.rectificacion.boletin_anterior_token', $originalToken)
            ->assertJsonCount(1, 'data.rectificacion.cambios')->assertJsonPath('data.rectificacion.cambios.0.materia', 'Matemáticas')
            ->assertJsonPath('data.rectificacion.cambios.0.anterior', '4.5')->assertJsonPath('data.rectificacion.cambios.0.vigente', '4.2')->json('data');
        $next = BoletinEmision::where('revision', 2)->sole();
        $this->assertSame($issued->id, $next->rectifica_id);
        $trace = $next->snapshot['rectificacion']['cambios'][0];
        $this->assertSame($received['certificados'][0]['resultados'][1]['token'], $trace['equivalencia_token']);
        $this->assertSame(1, $trace['version']);
        $oldSubjects = collect($issued->snapshot['asignaturas'])->keyBy('nombre');
        $newSubjects = collect($next->snapshot['asignaturas'])->keyBy('nombre');
        $this->assertSame('4.2', $newSubjects['Matemáticas']['resultado']['display_value']);
        $this->assertSame('4.2', $newSubjects['Química']['resultado']['display_value']);
        $this->assertSame($oldSubjects['Química'], $newSubjects['Química']);
        $this->assertNotSame($issued->pdf_path, $next->pdf_path);
        $newPdf = $this->get($pdfUrl($corrected['token']))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $newPdf);
        $this->assertSame($next->pdf_sha256, hash('sha256', $newPdf));
        $this->assertNotSame($originalPdf, $newPdf);
        $this->assertSame($originalPdf, $this->get($pdfUrl($originalToken))->assertOk()->getContent());
        $this->postJson($issuePath.$studentToken.'/emitir', $selection)->assertOk()->assertJsonPath('data.token',$corrected['token']);
        $this->getJson($issuePath.$studentToken)->assertOk()->assertJsonCount(2,'data')
            ->assertJsonPath('data.0.rectificacion.boletin_anterior_token',$originalToken);
        $this->postJson($issuePath.'documentos/'.$corrected['token'].'/publicar')->assertOk();
        $this->assertSame(2, BoletinEmision::count());
        $this->assertSame('sustituido', $issued->fresh()->estado);
        $this->assertSame($originalPdf, $this->get($pdfUrl($originalToken))->assertOk()->getContent());
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->getJson($issuePath.$studentToken)->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.rectificacion.boletin_anterior_token',null)->assertJsonCount(0,'data.0.rectificacion.cambios');
        $this->get($pdfUrl($originalToken))->assertForbidden();
        $this->assertSame($newPdf, $this->get($pdfUrl($corrected['token']))->assertOk()->getContent());

        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
        $assessment = $this->verifyChemistryRectification($studentToken, $period(1), $report);
        $third = $this->postJson($issuePath.$studentToken.'/emitir', $selection)->assertOk()
            ->assertJsonPath('data.revision', 3)->assertJsonPath('data.rectificacion.revision_anterior', 2)
            ->assertJsonPath('data.rectificacion.boletin_anterior_token', $corrected['token'])
            ->assertJsonCount(1, 'data.rectificacion.cambios')->assertJsonPath('data.rectificacion.cambios.0.materia', 'Química')
            ->assertJsonPath('data.rectificacion.cambios.0.anterior', '4.2')->assertJsonPath('data.rectificacion.cambios.0.vigente', '4.0')->json('data.token');
        $last = BoletinEmision::where('revision', 3)->sole();
        $this->assertSame($next->id, $last->rectifica_id);
        $this->assertSame($assessment, $last->snapshot['rectificacion']['cambios'][0]['valoracion_token']);
        $this->assertSame(1, $last->snapshot['rectificacion']['cambios'][0]['version']);
        $lastSubjects = collect($last->snapshot['asignaturas'])->keyBy('nombre');
        $this->assertSame($newSubjects['Matemáticas'], $lastSubjects['Matemáticas']);
        $this->assertSame('4.0', $lastSubjects['Química']['resultado']['display_value']);
        $this->postJson($issuePath.$studentToken.'/emitir', $selection)->assertOk()->assertJsonPath('data.token', $third);
        $this->postJson($issuePath.'documentos/'.$third.'/publicar')->assertOk();
        $this->assertSame($originalPdf, $this->get($pdfUrl($originalToken))->assertOk()->getContent());
        $this->assertSame($newPdf, $this->get($pdfUrl($corrected['token']))->assertOk()->getContent());
        $thirdPdf = $this->get($pdfUrl($third))->assertOk()->getContent();
        $this->assertSame($last->pdf_sha256, hash('sha256', $thirdPdf));
        $this->getJson($issuePath.$studentToken)->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.1.rectificacion.boletin_anterior_token', $originalToken);
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->getJson($issuePath.$studentToken)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.token', $third);
        $this->get($pdfUrl($corrected['token']))->assertForbidden();
    }

    private function verifyChemistryRectification(string $student, string $period, string $report): string
    {
        $path = $this->base.'/matriculas/'.$student.'/valoraciones-ingreso';
        $assessment = collect($this->getJson($path)->assertOk()->json('valoraciones'))->firstWhere('periodo_token', $period);
        $this->assertSame('4.2', $assessment['decision']['valor']);
        $this->assertSame('Química', $assessment['propuesta']['materia']);
        $endpoint = $path.'/'.$assessment['token'].'/rectificaciones';
        $operator = User::where('email', 'correccion@example.test')->firstOrFail();
        $operator->givePermissionTo(EntryAssessments::APPROVE);
        app('auth')->forgetGuards();
        $this->withToken($operator->createToken('assessment-test')->plainTextToken);
        $data = ['valor' => '4.0', 'version' => 0, 'motivo' => 'La revisión de la rúbrica de Química corrige la suma del segundo periodo.'];
        $evidence = "%PDF-1.4\n% Acta de valoración de Química\n%%EOF";
        $send = fn ($fields) => $this->post($endpoint, [...$fields, 'soporte' => UploadedFile::fake()->createWithContent('acta-quimica.pdf', $evidence)], ['Accept' => 'application/json']);
        $this->getJson($path)->assertOk()->assertJsonPath('puede_rectificar', false);
        $send($data)->assertForbidden();
        $operator->givePermissionTo(EntryAssessments::RECTIFY);
        app('auth')->forgetGuards();
        $this->postJson($endpoint, $data)->assertUnprocessable()->assertJsonValidationErrors('soporte');
        $send([...$data, 'motivo' => ' '])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $send([...$data, 'valor' => '4.2'])->assertUnprocessable();
        $send([...$data, 'valor' => '6'])->assertUnprocessable();
        $send($data)->assertNoContent();
        $send($data)->assertNoContent();
        $send([...$data, 'valor' => '4.1'])->assertConflict();
        $current = collect($this->getJson($path)->assertOk()->json('valoraciones'))->firstWhere('token', $assessment['token']);
        $this->assertSame(1, $current['version']);
        $this->assertSame('4', $current['decision']['valor']);
        $this->assertSame($assessment['propuesta'], $current['propuesta']);
        $this->assertCount(1, $current['rectificaciones']);
        $change = $current['rectificaciones'][0];
        $this->assertSame('4.2', $change['anterior']['valor']);
        $this->assertSame($operator->name, $change['nueva']['responsable']);
        $this->assertSame($data['motivo'], $change['motivo']);
        $this->assertNotEmpty($change['registrado_en']);
        $this->assertSame($evidence, $this->get($endpoint.'/'.$change['archivo_token'])->assertOk()->assertDownload('acta-quimica.pdf')->streamedContent());
        $this->assertSame(1, DB::table('audit_logs')->where('accion', 'UPDATE')->where('recurso', EntryAssessments::RECTIFICATION_RESOURCE)->count());
        app('auth')->forgetGuards();
        $rows = collect($this->withToken($this->staffToken)->getJson($report)->assertOk()->json('data.asignaturas'))->keyBy('nombre');
        $this->assertSame('4.2', $rows['Matemáticas']['periodos'][1]['display_value']);
        $this->assertSame(['3.8','4','4','4'], array_column($rows['Química']['periodos'], 'display_value'));
        $this->assertCount(4, $rows['Química']['anual']['trace']['inputs']);
        $chemAssignment = AsignacionDocente::where('materia_id', Materia::where('nombre', 'Química')->sole()->id)->sole();
        $sheet = $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $chemAssignment->id).'/'.$period;
        $studentGrade = collect($this->getJson($sheet)->assertOk()->json('data.resultados'))->firstWhere('matricula_token', $student);
        $this->assertSame('valoracion', $studentGrade['origen']);
        $this->assertSame('4', $studentGrade['display_value']);

        return $assessment['token'];
    }

    private function verifyMathRectification(Matricula $enrollment, string $result, string $sheet, string $report, string $foreignEnrollment): void
    {
        $path = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $enrollment->id).'/definitivas-externas';
        $endpoint = $path.'/'.$result.'/rectificaciones';
        $chemistry = fn () => collect($this->getJson($report)->assertOk()->json('data.asignaturas'))->firstWhere('nombre','Química');
        $before = $chemistry();
        $this->assertSame(['3.8','4.2','4','4'], array_column($before['periodos'], 'display_value'));
        $original = $this->getJson($path)->assertOk()->json('certificados.0.resultados.1');
        $this->assertSame('4.5', $original['decision']['valor']);
        $pdf = "%PDF-1.4\n% Certificado corregido de Matemáticas\n%%EOF";
        $fields = ['version' => 0, 'valor' => '4.2', 'motivo' => 'Rectoría verificó el certificado corregido del colegio anterior.'];
        $send = fn ($url, $data) => $this->post($url, [...$data, 'soporte' => UploadedFile::fake()->createWithContent('certificado-corregido.pdf', $pdf)], ['Accept' => 'application/json']);
        $operator = User::create(['name' => 'Responsable académico', 'email' => 'correccion@example.test',
            'role' => 'secretaria', 'status' => 'active', 'password' => 'Testing123!']);
        $operator->givePermissionTo(['academico.matriculas.gestionar', CertifiedExternalGrades::APPROVE]);
        app('auth')->forgetGuards();
        $this->withToken($operator->createToken('test')->plainTextToken);
        $this->getJson($path)->assertOk()->assertJsonPath('puede_rectificar',false);
        $send($endpoint,$fields)->assertForbidden();
        $operator->givePermissionTo(CertifiedExternalGrades::RECTIFY);
        app('auth')->forgetGuards();
        $this->getJson($path)->assertOk()->assertJsonPath('puede_rectificar',true);
        $this->postJson($endpoint,$fields)->assertUnprocessable()->assertJsonValidationErrors('soporte');
        $send($endpoint,[...$fields,'motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $send($endpoint,[...$fields,'valor' => '6'])->assertUnprocessable();
        $send($endpoint,[...$fields,'valor' => '4.50'])->assertUnprocessable();
        $send($path.'/1/rectificaciones',$fields)->assertNotFound();
        $year = $enrollment->anoLectivo;
        $previousState = $year->estado;
        $year->update(['estado' => 'archivado']);
        $this->getJson($path)->assertOk()->assertJsonPath('puede_rectificar',false);
        $send($endpoint,$fields)->assertUnprocessable();
        $year->update(['estado' => $previousState]);
        $otherStudent = User::create(['name' => 'Otro expediente', 'email' => 'otra-ficha@example.test',
            'role' => 'estudiante', 'status' => 'active', 'password' => 'Testing123!']);
        $peer = Matricula::create(['ano_lectivo_id' => $enrollment->ano_lectivo_id, 'grupo_id' => $enrollment->grupo_id, 'estudiante_id' => $otherStudent->id, 'estado' => 'activa']);
        $otherEndpoint = $this->base.'/matriculas/'.OpaqueUrlToken::for('matricula', $peer->id).'/definitivas-externas/'.$result.'/rectificaciones';
        $send($otherEndpoint,$fields)->assertNotFound();
        $foreignEndpoint = $this->base.'/matriculas/'.$foreignEnrollment.'/definitivas-externas/'.$result.'/rectificaciones';
        $send($foreignEndpoint,$fields)->assertNotFound();
        $count = count(Storage::disk('tenant')->allFiles());
        $send($endpoint,$fields)->assertNoContent();
        $send($endpoint,$fields)->assertNoContent();
        $send($endpoint,[...$fields,'valor' => '4.1'])->assertConflict();
        $this->assertCount($count + 1, Storage::disk('tenant')->allFiles());
        $this->assertSame(1, DB::table('audit_logs')->where('recurso','matricula_rectificacion_equivalencia')->where('accion','UPDATE')->count());
        $response = $this->getJson($path)->assertOk()->assertJsonCount(1,'certificados.0.resultados.1.rectificaciones')
            ->assertJsonPath('certificados.0.resultados.1.version',1)->assertJsonPath('certificados.0.resultados.1.decision.valor','4.2');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', $response->getContent());
        $current = $response->json('certificados.0.resultados.1');
        $change = $current['rectificaciones'][0];
        $this->assertSame($original['valor_original'],$current['valor_original']);
        $this->assertSame('4.5',$change['anterior']['valor']);
        $this->assertSame($original['decision']['responsable'],$change['anterior']['responsable']);
        $this->assertSame($original['aprobado_en'],$change['aprobacion_anterior']);
        $this->assertSame($operator->name,$change['nueva']['responsable']);
        $this->assertSame($fields['motivo'],$change['motivo']);
        $this->get($otherEndpoint.'/'.$change['archivo_token'])->assertNotFound();
        $this->get($foreignEndpoint.'/'.$change['archivo_token'])->assertNotFound();
        $this->assertSame($pdf,$this->get($endpoint.'/'.$change['archivo_token'])->assertOk()->assertDownload('certificado-corregido.pdf')->streamedContent());
        $this->getJson($path.'/'.$response->json('certificados.0.resultados.0.token').'/rectificaciones/'.$change['archivo_token'])->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($enrollment->estudiante->createToken('test')->plainTextToken)->get($endpoint.'/'.$change['archivo_token'])->assertForbidden();
        app('auth')->forgetGuards();
        $sheetRows = collect($this->withToken($this->staffToken)->getJson($sheet)->assertOk()->json('data.resultados'));
        $studentRow = $sheetRows->firstWhere('matricula_token', OpaqueUrlToken::for('matricula', $enrollment->id));
        $this->assertSame('externa', $studentRow['origen']);
        $this->assertSame('4.2', $studentRow['display_value']);
        $math = collect($this->getJson($report)->assertOk()->json('data.asignaturas'))->firstWhere('nombre','Matemáticas');
        $this->assertSame(['4','4.2','3.5','4.8'],array_column($math['periodos'],'display_value'));
        $this->assertCount(4,$math['anual']['trace']['inputs']);
        $this->assertSame('4.1',$math['anual']['display_value']);
        $this->assertEquals($before,$chemistry());
    }

    private function verifyChemistryWithoutEquivalence(Matricula $enrollment, User $rector, Collection $periods, array $request, array $received): void
    {
        $studentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $path = $this->base.'/matriculas/'.$studentToken.'/definitivas-externas';
        $report = $this->base.'/evaluacion/boletines/'.$studentToken;
        $track = $path.'/pendientes';
        $period = fn ($i) => OpaqueUrlToken::for('periodo', $periods[$i]->id);
        $chem = Materia::create(['ano_lectivo_id' => $enrollment->ano_lectivo_id, 'nombre' => 'Química', 'intensidad_horaria' => 2, 'estado' => 'activo']);
        DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $enrollment->ano_lectivo_id, 'grado_id' => $enrollment->grupo->grado_id, 'materia_id' => $chem->id]);
        $chemAssignment = AsignacionDocente::create(['ano_lectivo_id' => $enrollment->ano_lectivo_id, 'grupo_id' => $enrollment->grupo_id, 'materia_id' => $chem->id, 'docente_id' => $rector->id]);
        $chemToken = OpaqueUrlToken::for('materia', $chem->id);
        $chemSheet = fn ($i) => $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $chemAssignment->id).'/'.$period($i);
        $chemGrade = fn ($i) => ['notas' => [['matricula_token' => $studentToken, 'version' => 0, 'valor' => '4',
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $this->localMathActivity($chemAssignment, $periods[$i])->id)]]];
        $assessmentPath = $this->base.'/matriculas/'.$studentToken.'/valoraciones-ingreso';
        $file = $received['certificados'][0]['archivo_token'];
        $gapPath = $path.'/certificados/'.$file.'/sin-equivalencia';
        $reason = 'El certificado de origen no incluye Química en los dos primeros periodos.';
        $gap = [...$request, 'materia_token' => $chemToken, 'motivo' => $reason];
        unset($gap['fecha_ingreso']);
        $this->getJson($path)->assertOk()->assertJsonPath('certificados.0.resultados.1.materia_token', $request['materia_token'])
            ->assertJsonPath('certificados.0.resultados.1.periodo_token', $period(1));
        $this->postJson($track, [...$request, 'materia_token' => $chemToken, 'periodos' => [$period(0)]])->assertNoContent();
        $this->postJson($gapPath, [...$gap,'motivo' => ''])->assertUnprocessable();
        $this->postJson($gapPath, [...$gap,'materia_token' => $request['materia_token']])->assertConflict();
        $this->postJson($gapPath, [...$gap,'periodos' => [$period(0), $period(2)]])->assertUnprocessable();
        $this->postJson($path.'/certificados/'.str_repeat('x',24).'/sin-equivalencia', $gap)->assertNotFound();
        app('auth')->forgetGuards();
        $this->withToken($enrollment->estudiante->createToken('test')->plainTextToken)->postJson($gapPath, $gap)->assertForbidden();
        app('auth')->forgetGuards();
        $registrar = User::create(['name' => 'Registro de certificados', 'email' => 'registro.certificados@example.test',
            'role' => 'secretaria', 'status' => 'active', 'password' => 'Testing123!']);
        $registrar->givePermissionTo(['academico.matriculas.gestionar', CertifiedExternalGrades::REGISTER]);
        $this->withToken($registrar->createToken('test')->plainTextToken)->postJson($gapPath, $gap)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($gapPath, $gap)->assertNoContent();
        $this->postJson($gapPath, $gap)->assertNoContent();
        $this->assertSame(2, DB::table('audit_logs')->where('recurso','matricula_sin_equivalencia')->count());
        $this->postJson($gapPath, [...$gap, 'motivo' => 'Otro motivo'])->assertConflict();
        $this->getJson($assessmentPath)->assertOk()->assertJsonCount(2,'sin_equivalencia')->assertJsonCount(0,'certificados_pendientes')
            ->assertJsonPath('sin_equivalencia.1.certificado_revisado',$file)->assertJsonPath('sin_equivalencia.0.motivo_sin_equivalencia',$reason);
        $periods[0]->update(['estado' => 'abierto']);
        $this->putJson($chemSheet(0), $chemGrade(0))->assertUnprocessable();
        $periods[0]->update(['estado' => 'cerrado']);
        foreach ([2,3] as $i) {
            $this->putJson($chemSheet($i), $chemGrade($i))->assertOk();
        }
        $checkGrades = function (array $expected) use ($report, $chemSheet) {
            $rows = collect($this->getJson($report)->assertOk()->json('data.asignaturas'))->keyBy('nombre');
            $this->assertSame(['4','4.5','3.5','4.8'], array_column($rows['Matemáticas']['periodos'],'display_value'));
            $this->assertSame('4.2', $rows['Matemáticas']['anual']['display_value']);
            foreach ($expected as $i => $value) {
                $origin = $value === null ? 'sin_equivalencia' : 'valoracion';
                $this->assertSame($origin, $rows['Química']['periodos'][$i]['origen']);
                $this->assertSame($value, $rows['Química']['periodos'][$i]['display_value'] ?? null);
                $this->getJson($chemSheet($i))->assertOk()->assertJsonPath('data.resultados.0.origen',$origin);
            }
            if (in_array(null,$expected,true)) {
                $this->assertSame('pendiente', $rows['Química']['anual']['estado']);
            } else {
                $this->assertSame('4', $rows['Química']['anual']['display_value']);
                $this->assertCount(4, $rows['Química']['anual']['trace']['inputs']);
            }
        };
        $checkGrades([null,null]);
        foreach (['3.8','4.2'] as $i => $value) {
            $proposal = ['fecha_ingreso' => $request['fecha_ingreso'], 'materia_token' => $chemToken, 'periodo_token' => $period($i),
                'version_escala' => $received['escala']['version'], 'valor' => $value, 'motivo' => 'Valoración individual de Química.'];
            $this->postJson($assessmentPath,$proposal)->assertUnprocessable()->assertJsonValidationErrors('soporte');
            $this->post($assessmentPath,[...$proposal,'soporte' => UploadedFile::fake()->createWithContent("quimica-$i.pdf","%PDF-1.4\n% Química $i\n%%EOF")],
                ['Accept' => 'application/json'])->assertNoContent();
        }
        $assessments = $this->getJson($assessmentPath)->assertJsonCount(2,'valoraciones')->json('valoraciones');
        $checkGrades([null,null]);
        $profile = $this->base.'/ingreso/mis-resultados';
        $this->getJson($profile)->assertForbidden();
        $ana = $enrollment->estudiante;
        $ana->assignRole('estudiante');
        $anaSession = $ana->createToken('perfil')->plainTextToken;
        $checkProfile = function (array $chemistry) use ($profile, $anaSession) {
            app('auth')->forgetGuards();
            $rows = $this->withToken($anaSession)->getJson($profile)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonCount(4, 'data')->json('data');
            $this->assertSame(['Matemáticas','Matemáticas','Química','Química'], array_column($rows, 'materia'));
            $this->assertSame(['Primero','Segundo','Primero','Segundo'], array_column($rows, 'periodo'));
            $this->assertSame(['4.0','4.5', ...$chemistry], array_column($rows, 'nota'));
            foreach ($rows as $i => $row) {
                $this->assertEqualsCanonicalizing(['token','ano','materia','periodo','origen','estado','nota'], array_keys($row));
                $this->assertSame($i < 2 ? 'externa' : ($row['nota'] === null ? 'sin_equivalencia' : 'valoracion'), $row['origen']);
                $this->assertSame($row['nota'] === null ? 'pendiente' : 'calculado', $row['estado']);
            }
            app('auth')->forgetGuards();
            $this->withToken($this->staffToken);
        };
        $checkProfile([null, null]);
        $peer = User::create(['name' => 'Estudiante de prueba', 'email' => 'estudiante.perfil@example.test', 'role' => 'estudiante',
            'status' => 'active', 'password' => 'Testing123!']);
        $peer->assignRole('estudiante');
        app('auth')->forgetGuards();
        $this->withToken($peer->createToken('perfil')->plainTextToken)->getJson($profile.'?matricula_token='.$studentToken)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($profile.'/1')->assertNotFound();
        app('auth')->forgetGuards();
        $otherSchool = \App\Models\Tenant::where('slug', 'certificado-tramite')->firstOrFail();
        $otherSchool->update(['status' => 'active']);
        $otherOrigin = 'http://'.$otherSchool->slug.'.localhost';
        $this->withHeader('Origin', $otherOrigin)->withToken($anaSession)
            ->getJson($otherOrigin.'/api/ingreso/mis-resultados')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withHeader('Origin', 'http://inscripciones.localhost')->withToken($this->staffToken);
        foreach ($assessments as $i => $assessment) {
            $this->get($assessmentPath.'/soportes/'.$assessment['archivo_token'])->assertOk()->assertDownload("quimica-$i.pdf");
            $approval = ['valor' => $assessment['propuesta']['valor'], 'version_escala' => $received['escala']['version'], 'criterio' => 'Soporte individual revisado.'];
            $this->postJson($assessmentPath.'/'.$assessment['token'].'/aprobar', $approval)->assertNoContent();
            $checkGrades($i === 0 ? ['3.8',null] : ['3.8','4.2']);
            $checkProfile($i === 0 ? ['3.8',null] : ['3.8','4.2']);
            $followUp = collect($this->getJson($path)->assertOk()->json('pendientes'))->where('certificado_revisado',$file)->values();
            $this->assertSame($rector->name,$followUp[$i]['aprobado_por']);
            if ($i === 0) {
                $this->assertNull($followUp[1]['aprobado_en']);
            }
        }
    }

    private function thirdPeriodEnrollment(string $firstPeriodState = Periodo::ESTADO_CERRADO): array
    {
        Storage::fake('tenant');
        $this->app->instance(FileScanner::class, new NullScanner);
        $this->withToken($this->staffToken)->getJson($this->base.'/matriculas')->assertOk();
        $year = AnoLectivo::firstOrFail();
        $start = today()->startOfYear();
        $this->travelTo($start->copy()->addMonths(7));
        $year->update(['fecha_inicio' => $start, 'fecha_fin' => $start->copy()->endOfYear()]);
        $periods = collect(['Primero','Segundo','Tercero','Cuarto'])->map(function ($name, $i) use ($year, $start, $firstPeriodState) {
            $from = $start->copy()->addMonths(3 * $i);
            return Periodo::create(['ano_lectivo_id' => $year->id, 'nombre' => $name, 'orden' => $i + 1,
                'fecha_inicio' => $from, 'fecha_fin' => $from->copy()->addMonths(3)->subDay(),
                'estado' => [$firstPeriodState,'cerrado','abierto','planificado'][$i]]);
        });
        $grade = Grado::firstOrFail();
        $group = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'nombre' => 'A', 'estado' => 'activo']);
        $math = Materia::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Matemáticas', 'intensidad_horaria' => 4, 'estado' => 'activo']);
        DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $year->id, 'grado_id' => $grade->id, 'materia_id' => $math->id]);
        $student = User::create(['name' => 'Ana Traslado', 'email' => 'traslado@example.test', 'role' => 'estudiante', 'status' => 'active', 'password' => 'Testing123!']);
        $enrollment = Matricula::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id, 'estudiante_id' => $student->id, 'estado' => 'activa']);
        return [$year, $periods, $group, $math, $student, $enrollment];
    }

    private function configureMathGrades(AnoLectivo $year, Materia $math, Grupo $group): array
    {
        $scale = EscalaValorativa::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Institucional', 'tipo' => 'numerica',
            'valor_min' => 0, 'valor_max' => 5, 'decimales' => 1]);
        $method = MetodoAprobacion::create(['ano_lectivo_id' => $year->id, 'calculo_nota' => 'promedio_simple', 'nota_minima' => 3, 'ambito' => 'materia']);
        $year->update(['siee' => ['escala_id' => $scale->id, 'metodo_id' => $method->id]]);
        $rector = User::where('role', 'rector')->firstOrFail();
        $assignment = AsignacionDocente::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id, 'materia_id' => $math->id, 'docente_id' => $rector->id]);
        return [$scale, $rector, $assignment];
    }

    public function test_third_period_entry_without_grades_requires_supported_assessment_and_approval_per_period(): void
    {
        $foreign = app(\App\Services\TenantProvisioner::class)->provision(['name' => 'Otro colegio', 'slug' => 'otro-ingreso',
            'plan' => 'premium', 'rector_email' => 'rector@otro-ingreso.test'])['tenant'];
        $foreignToken = $foreign->run(fn () => OpaqueUrlToken::for('matricula', 1));
        [$year, $periods, $group, $math, $student, $enrollment] = $this->thirdPeriodEnrollment();
        [, $rector, $assignment] = $this->configureMathGrades($year, $math, $group);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $base = $this->base.'/matriculas/';
        $path = $base.$token.'/valoraciones-ingreso';
        $report = $this->base.'/evaluacion/boletines/'.$token;
        $periodToken = fn ($i) => OpaqueUrlToken::for('periodo', $periods[$i]->id);
        $sheet = fn ($i) => $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $assignment->id).'/'.$periodToken($i);
        $pdf = "%PDF-1.4\n% Evidencia de la valoración individual\n%%EOF";
        $details = $this->getJson($path)->assertOk()->assertJsonCount(0, 'antecedentes')->assertJsonCount(0, 'valoraciones')->json();
        $this->getJson($base.'1/valoraciones-ingreso')->assertNotFound();
        $this->getJson($base.$foreignToken.'/valoraciones-ingreso')->assertNotFound();
        foreach ([0, 1] as $i) {
            $this->getJson($sheet($i))->assertOk()->assertJsonPath('data.resultados.0.estado', 'pendiente');
        }
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.estado', 'pendiente')
            ->assertJsonPath('data.asignaturas.0.periodos.1.estado', 'pendiente')->assertJsonPath('data.asignaturas.0.anual.estado', 'pendiente');
        $data = ['fecha_ingreso' => $periods[2]->fecha_inicio->toDateString(), 'materia_token' => OpaqueUrlToken::for('materia', $math->id),
            'periodo_token' => $periodToken(0), 'version_escala' => $details['escala']['version'], 'valor' => '3.8',
            'motivo' => 'Ingreso sin notas previas. Se valoró resolución de problemas mediante evaluación individual.'];
        $submit = fn ($fields) => $this->post($path, [...$fields, 'soporte' => UploadedFile::fake()->createWithContent('rubrica.pdf', $pdf)], ['Accept' => 'application/json']);
        $this->postJson($path, $data)->assertUnprocessable()->assertJsonValidationErrors('soporte');
        $submit([...$data, 'motivo' => ''])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $submit([...$data, 'periodo_token' => $periodToken(2)])->assertUnprocessable();
        $submit([...$data, 'valor' => '7'])->assertUnprocessable();
        $submit([...$data, 'version_escala' => str_repeat('x', 64)])->assertConflict();
        $submit($data)->assertNoContent();
        $submit($data)->assertNoContent();
        $submit([...$data, 'valor' => '3.9'])->assertConflict();
        $submit([...$data, 'periodo_token' => $periodToken(1), 'valor' => '4.2'])->assertNoContent();
        $rows = $this->getJson($path)->assertOk()->assertJsonCount(2, 'valoraciones')->assertJsonPath('valoraciones.0.decision', null)->json('valoraciones');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', $this->getJson($path)->getContent());
        $this->assertCount(2, Storage::disk('tenant')->allFiles());
        $download = $path.'/soportes/'.$rows[0]['archivo_token'];
        $this->assertSame($pdf, $this->get($download)->assertOk()->assertDownload('rubrica.pdf')->streamedContent());
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.estado', 'pendiente')
            ->assertJsonPath('data.asignaturas.0.periodos.1.estado', 'pendiente');
        $approve = fn ($i, $fields) => $this->postJson($path.'/'.$rows[$i]['token'].'/aprobar', $fields);
        $decision = ['valor' => '3.8', 'version_escala' => $data['version_escala'], 'criterio' => 'Rúbrica y evidencia revisadas por rectoría.'];
        $approvalStaff = User::create(['name' => 'Registro escolar', 'email' => 'registro@example.test', 'password' => 'Testing123!', 'role' => 'secretaria', 'status' => 'active']);
        $approvalStaff->givePermissionTo(['academico.matriculas.gestionar', \App\Services\Ingreso\EntryAssessments::REGISTER]);
        app('auth')->forgetGuards();
        $this->withToken($approvalStaff->createToken('test')->plainTextToken);
        $this->getJson($path)->assertOk()->assertJsonPath('puede_aprobar', false);
        $approve(0, $decision)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->get($download)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken);
        $peerUser = User::create(['name' => 'Otra matrícula', 'email' => 'sinnotas-otra@example.test', 'role' => 'estudiante', 'status' => 'active', 'password' => 'Testing123!']);
        $peer = Matricula::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id, 'estudiante_id' => $peerUser->id, 'estado' => 'activa']);
        $peerPath = $base.OpaqueUrlToken::for('matricula', $peer->id).'/valoraciones-ingreso';
        $this->getJson($peerPath.'/soportes/'.$rows[0]['archivo_token'])->assertNotFound();
        $this->postJson($peerPath.'/'.$rows[0]['token'].'/aprobar', $decision)->assertNotFound();
        $approve(0, [...$decision, 'criterio' => ''])->assertUnprocessable();
        $approve(0, $decision)->assertNoContent();
        $approve(0, $decision)->assertNoContent();
        $approve(0, [...$decision, 'valor' => '4'])->assertConflict();
        $planilla = collect($this->getJson($sheet(0))->assertOk()->json('data.resultados'));
        $assessed = $planilla->firstWhere('matricula_token', $token);
        $this->assertSame('valoracion', $assessed['origen']);
        $this->assertSame('3.8', $assessed['display_value']);
        $this->assertSame([], $assessed['secciones']);
        $this->assertSame('pendiente', $planilla->firstWhere('matricula_token', '!=', $token)['estado']);
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.1.estado', 'pendiente')
            ->assertJsonPath('data.asignaturas.0.anual.estado', 'pendiente');
        $approve(1, [...$decision, 'valor' => '4.2'])->assertNoContent();
        $this->assertSame(0, Calificacion::count());
        foreach ([2 => '4.4', 3 => '4.0'] as $i => $value) {
            $periods[$i]->update(['estado' => 'abierto']);
            $activity = $this->localMathActivity($assignment, $periods[$i]);
            $this->putJson($sheet($i), ['notas' => [['matricula_token' => $token,
                'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id), 'valor' => $value, 'version' => 0]]])->assertOk();
        }
        $subject = $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.anual.display_value', '4.1')->json('data.asignaturas.0');
        $this->assertSame(['3.8','4.2','4.4','4'], array_column($subject['periodos'], 'display_value'));
        $this->assertCount(4, $subject['anual']['trace']['inputs']);
        $this->assertSame(2, DB::table('audit_logs')->where('recurso', 'matricula_valoracion_ingreso')->where('accion', 'APPROVE')->count());
        $this->getJson($path)->assertOk()->assertJsonPath('valoraciones.0.decision.responsable', $rector->name)
            ->assertJsonPath('valoraciones.0.decision.criterio', $decision['criterio']);
        $this->assertSame(['cerrado','cerrado'], Periodo::orderBy('orden')->limit(2)->pluck('estado')->all());

        // A classmate with even one local grade cannot use the missing-grades procedure for that slot.
        $activity = $this->localMathActivity($assignment, $periods[0]);
        Calificacion::create(['matricula_id' => $peer->id, 'actividad_id' => $activity->id, 'valor' => '3.0', 'updated_by' => $rector->id]);
        $this->post($peerPath, [...$data, 'soporte' => UploadedFile::fake()->createWithContent('rubrica.pdf', $pdf)],
            ['Accept' => 'application/json'])->assertConflict();
        $this->getJson($peerPath)->assertOk()->assertJsonCount(0, 'valoraciones')->assertJsonCount(1, 'antecedentes');

        $external = $base.$token.'/definitivas-externas';
        $this->post($external, ['fecha_ingreso' => $data['fecha_ingreso'], 'colegio_origen' => 'Colegio anterior', 'grado_origen' => 'Primero',
            'escala_origen' => '1 a 5', 'certificado' => UploadedFile::fake()->createWithContent('certificado.pdf', $pdf),
            'resultados' => [['materia_origen' => 'Matemáticas', 'periodo_origen' => 'Primero', 'valor_original' => '4',
                'desde' => $periods[0]->fecha_inicio->toDateString(), 'hasta' => $periods[0]->fecha_fin->toDateString()]]],
            ['Accept' => 'application/json'])->assertNoContent();
        $externalResult = $this->getJson($external)->assertOk()->json('certificados.0.resultados.0.token');
        $this->postJson($external.'/'.$externalResult.'/aprobar', [...$decision, 'valor' => '4', 'materia_token' => $data['materia_token'],
            'periodo_token' => $periodToken(0)])->assertNoContent();
        $select = $external.'/'.$externalResult.'/eleccion';
        $comparison = $this->getJson($external)->assertOk()->json('certificados.0.resultados.0.eleccion');
        $this->assertEquals(3.8, $comparison['colegio']);
        $this->assertEquals(4, $comparison['certificado']);
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.anual.estado', 'pendiente');
        $choice = ['version' => 0, 'fuente' => 'certificado', 'motivo' => 'Se recibió el certificado definitivo.', 'huella' => $comparison['huella']];
        $this->postJson($select, $choice)->assertNoContent();
        $subject = $this->getJson($report)->assertOk()->json('data.asignaturas.0');
        $this->assertSame(['4','4.2','4.4','4'], array_column($subject['periodos'], 'display_value'));
        $this->assertCount(4, $subject['anual']['trace']['inputs']);
        $this->postJson($external.'/'.$externalResult.'/rectificaciones', [])->assertUnprocessable();
        $this->post($external.'/'.$externalResult.'/rectificaciones', ['version' => 0, 'valor' => '4.6', 'motivo' => 'Equivalencia recalculada.',
            'soporte' => UploadedFile::fake()->createWithContent('aclaracion.pdf', $pdf)], ['Accept' => 'application/json'])->assertNoContent();
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.origen', 'pendiente_eleccion');
        $this->postJson($select, [...$choice, 'version' => 1])->assertConflict();
        $updated = $this->getJson($external)->assertOk()->json('certificados.0.resultados.0.eleccion');
        $this->assertEquals(4, $updated['historial'][0]['certificado']);
        $this->assertEquals(4.6, $updated['certificado']);
        $this->postJson($select, [...$choice, 'fuente' => 'colegio', 'motivo' => 'Se acepta la valoración individual del colegio.',
            'version' => 1, 'huella' => $updated['huella']])->assertNoContent();
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.origen', 'valoracion')
            ->assertJsonPath('data.asignaturas.0.anual.display_value', '4.1');
    }

    public function test_third_period_grade_change_preserves_math_and_leaves_new_chemistry_pending(): void
    {
        [$year, $periods, $first, $math, , $enrollment] = $this->thirdPeriodEnrollment();
        [, $rector, $source] = $this->configureMathGrades($year, $math, $first);
        $enrollment->created_at = $year->fecha_inicio;
        $enrollment->save();
        $secondGrade = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $first->grado->nivel_id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $second = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $secondGrade->id, 'nombre' => 'B', 'estado' => 'activo']);
        $chemistry = Materia::create(['ano_lectivo_id' => $year->id, 'nombre' => 'Química', 'intensidad_horaria' => 2, 'estado' => 'activo']);
        foreach ([$math, $chemistry] as $subject) {
            DB::table('materias_curriculares')->insert(['ano_lectivo_id' => $year->id, 'grado_id' => $secondGrade->id, 'materia_id' => $subject->id]);
            $assignments[$subject->id] = AsignacionDocente::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $second->id,
                'materia_id' => $subject->id, 'docente_id' => $rector->id]);
        }
        $saveOriginal = fn ($activity, $value) => Calificacion::create(['matricula_id' => $enrollment->id, 'actividad_id' => $activity->id,
            'valor' => $value, 'updated_by' => $rector->id]);
        $firstActivity = $this->localMathActivity($source, $periods[0]);
        $secondActivity = ActividadEvaluacion::create(['componente_id' => $firstActivity->componente_id, 'nombre' => 'Problemas', 'fecha' => $periods[0]->fecha_fin]);
        $saveOriginal($firstActivity, '3');
        $saveOriginal($secondActivity, '5');
        $saveOriginal($this->localMathActivity($source, $periods[1]), '4.5');
        $this->localMathActivity($assignments[$math->id], $periods[0]);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $file = $this->base.'/matriculas/'.$token;
        $report = $this->base.'/evaluacion/boletines/'.$token;
        $sheet = fn ($subject, $i) => $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $assignments[$subject->id]->id)
            .'/'.OpaqueUrlToken::for('periodo', $periods[$i]->id);
        $payload = ['grupo_token' => OpaqueUrlToken::for('grupo', $second->id), 'motivo' => 'Cambio de grado autorizado durante el tercer periodo.',
            'fecha_efectiva' => $periods[2]->fecha_inicio->toDateString(), 'version' => 0];
        $this->postJson($file.'/correcciones', $payload)->assertNoContent();
        $this->postJson($file.'/correcciones', $payload)->assertNoContent();
        $this->getJson($file)->assertOk()->assertJsonPath('data.grupo', 'Segundo / B')->assertJsonPath('data.trayectoria.tiene_resultados_traslado', true)
            ->assertJsonPath('data.trayectoria.movimientos.0.motivo', $payload['motivo']);
        $this->assertSame(4, DB::table('matricula_resultados_traslado')->count());
        $this->assertSame(3, Calificacion::count());
        foreach ([0 => '4', 1 => '4.5'] as $i => $value) {
            $this->getJson($sheet($math, $i))->assertOk()->assertJsonPath('data.resultados.0.origen', 'traslado')
                ->assertJsonPath('data.resultados.0.display_value', $value)->assertJsonPath('data.resultados.0.secciones', []);
            $this->getJson($sheet($chemistry, $i))->assertOk()->assertJsonPath('data.resultados.0.origen', 'pendiente_traslado')
                ->assertJsonPath('data.resultados.0.estado', 'pendiente')->assertJsonMissingPath('data.resultados.0.exact_value');
        }
        $path = $file.'/valoraciones-ingreso';
        $form = $this->getJson($path)->assertOk()->assertJsonPath('fecha_ingreso', null)->assertJsonCount(4, 'traslados')->json();
        $slot = collect($form['traslados'])->first(fn ($r) => $r['materia_token'] === OpaqueUrlToken::for('materia', $chemistry->id)
            && $r['periodo_token'] === OpaqueUrlToken::for('periodo', $periods[0]->id));
        $data = ['traslado_token' => $slot['token'], 'materia_token' => $slot['materia_token'], 'periodo_token' => $slot['periodo_token'],
            'valor' => '3.8', 'version_escala' => $form['escala']['version'], 'motivo' => 'Se valoraron las competencias de Química del primer periodo.'];
        $submit = fn ($fields) => $this->post($path, [...$fields, 'soporte' => UploadedFile::fake()->createWithContent('rubrica.pdf', "%PDF-1.4\n% Rúbrica\n%%EOF")], ['Accept' => 'application/json']);
        $submit([...$data, 'traslado_token' => str_repeat('x', 24)])->assertConflict();
        $submit($data)->assertNoContent();
        $this->getJson($sheet($chemistry, 0))->assertOk()->assertJsonPath('data.resultados.0.estado', 'pendiente');
        $assessment = $this->getJson($path)->assertOk()->assertJsonPath('fecha_ingreso', null)->json('valoraciones.0.token');
        $this->postJson($path.'/'.$assessment.'/aprobar', ['valor' => '3.8', 'version_escala' => $data['version_escala'],
            'criterio' => 'Se revisó la evidencia de la valoración.'])->assertNoContent();
        foreach ([2 => '3.5', 3 => '4.8'] as $i => $value) {
            $periods[$i]->update(['estado' => 'abierto']);
            $activity = $this->localMathActivity($assignments[$math->id], $periods[$i]);
            $this->putJson($sheet($math, $i), ['notas' => [['matricula_token' => $token,
                'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id), 'valor' => $value, 'version' => 0]]])->assertOk();
        }
        $subjects = collect($this->getJson($report)->assertOk()->json('data.asignaturas'))->keyBy('nombre');
        $this->assertSame(['4', '4.5', '3.5', '4.8'], array_column($subjects['Matemáticas']['periodos'], 'display_value'));
        $this->assertSame('4.2', $subjects['Matemáticas']['anual']['display_value']);
        $this->assertCount(4, $subjects['Matemáticas']['anual']['trace']['inputs']);
        $this->assertSame('3.8', $subjects['Química']['periodos'][0]['display_value']);
        $this->assertSame('pendiente_traslado', $subjects['Química']['periodos'][1]['origen']);
        $this->assertSame('pendiente', $subjects['Química']['anual']['estado']);
        $this->assertSame(5, Calificacion::count());
        $rector->givePermissionTo(['boletines.observaciones.gestionar', 'boletines.emisiones.preparar', 'boletines.emisiones.ver']);
        $selection = ['contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $periods[0]->id)];
        $periods[0]->update(['estado' => 'abierto']);
        $this->putJson($this->base.'/boletines/observaciones/'.$token, [...$selection, 'version' => 0,
            'contenido' => 'Definitiva de Matemáticas conservada y valoración de Química aprobada.'])->assertOk();
        $periods[0]->update(['estado' => 'cerrado']);
        $issuePath = $this->base.'/boletines/emisiones/';
        $issued = $this->postJson($issuePath.$token.'/emitir', $selection)->assertOk()->json('data.token');
        $document = collect(BoletinEmision::sole()->snapshot['asignaturas'])->keyBy('nombre');
        $this->assertSame('traslado', $document['Matemáticas']['resultado']['origen']);
        $this->assertCount(1, $document['Matemáticas']['periodos_reconocidos']);
        $this->assertArrayHasKey('traslado_token', $document['Matemáticas']['periodos_reconocidos'][0]);
        $this->assertSame('valoracion', $document['Química']['resultado']['origen']);
        $bytes = $this->get($issuePath.'documentos/'.$issued.'/pdf')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->postJson($file.'/correcciones', [...$payload, 'version' => 1, 'rectifica_version' => 1,
            'grupo_token' => OpaqueUrlToken::for('grupo', $first->id), 'motivo' => 'Restituir el grupo de origen.'])->assertNoContent();
        $this->getJson($file)->assertOk()->assertJsonPath('data.grupo', 'Primero / A')->assertJsonPath('data.trayectoria.tiene_resultados_traslado', false);
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.0.display_value', '4')
            ->assertJsonMissingPath('data.asignaturas.0.periodos.0.traslado_token');
    }

    public function test_mid_third_period_group_change_consolidates_weighted_math_once(): void
    {
        $this->verifyMathTransfer(false);
    }

    public function test_individual_support_in_mid_third_period_group_change_counts_once(): void
    {
        $this->verifyMathTransfer(true);
    }

    private function verifyMathTransfer(bool $withSupport): void
    {
        [$year, $periods, $group, $math, $student, $enrollment] = $this->thirdPeriodEnrollment();
        [, $rector, $origin] = $this->configureMathGrades($year, $math, $group);
        $period = $periods[2];
        $cut = $period->fecha_inicio->copy()->addDays(45);
        $this->travelTo($cut->copy()->addWeek());
        $enrollment->created_at = $year->fecha_inicio;
        $enrollment->save();
        $target = Grupo::create(['ano_lectivo_id' => $year->id, 'grado_id' => $group->grado_id, 'nombre' => 'B', 'estado' => 'activo']);
        $destination = AsignacionDocente::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $target->id, 'materia_id' => $math->id, 'docente_id' => $rector->id]);
        $activities = function ($assignment, $entries) use ($period, $cut) {
            $component = ComponenteEvaluacion::create(['asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
                'nombre' => 'Actividades', 'es_directo' => true, 'modo' => 'WEIGHTED_AVERAGE', 'peso' => 100]);
            return collect($entries)->map(fn ($entry) => ActividadEvaluacion::create(['componente_id' => $component->id,
                'nombre' => $entry[0], 'peso' => $entry[1], 'fecha' => $cut->copy()->addDays($entry[2])->toDateString()]));
        };
        [$a1, $a2, $later] = $activities($origin, [['Taller',20,-20], ['Problemas',30,-1], ['Proyecto posterior',50,0]]);
        [$before, $b1, $b2] = $activities($destination, [['Taller anterior',20,-1], ['Laboratorio',60,0], ['Sustentación',20,3]]);
        $token = OpaqueUrlToken::for('matricula', $enrollment->id);
        $path = $this->base.'/matriculas/'.$token;
        $periodToken = OpaqueUrlToken::for('periodo', $period->id);
        $sheet = fn ($assignment) => $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $assignment->id).'/'.$periodToken;
        $grade = fn ($activity, $value, $version = 0) => ['matricula_token' => $token,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id), 'valor' => $value, 'version' => $version];
        $this->putJson($sheet($origin), ['notas' => [$grade($a1,'3'), $grade($a2,'5')]])->assertOk();
        foreach ([0 => '4', 1 => '4.5'] as $i => $value) {
            $act = $this->localMathActivity($origin, $periods[$i]);
            Calificacion::create(['actividad_id' => $act->id, 'matricula_id' => $enrollment->id, 'valor' => $value, 'updated_by' => $rector->id]);
        }
        $movement = ['grupo_token' => OpaqueUrlToken::for('grupo', $target->id), 'motivo' => 'Reubicación de grupo durante el tercer periodo.',
            'version' => 0, 'fecha_efectiva' => $cut->toDateString()];
        $this->postJson($path.'/correcciones', $movement)->assertNoContent();
        $this->postJson($path.'/correcciones', $movement)->assertNoContent();
        $this->getJson($path)->assertOk()->assertJsonPath('data.ver_consolidaciones', true);
        $casePath = $path.'/consolidaciones';
        $case = $this->getJson($casePath)->assertOk()->assertJsonCount(1, 'casos')->json('casos.0');
        $this->assertDoesNotMatchRegularExpression('/"(?:id|[a-z_]+_id)"\s*:/', json_encode($case));
        $this->assertSame(['Taller','Problemas'], array_column($case['tramos'][0]['evidencias'], 'nombre'));
        $this->assertSame('4.2', $case['tramos'][0]['subtotal']);
        $this->assertNull($case['tramos'][1]['subtotal']);
        $approve = $casePath.'/'.$case['token'].'/aprobar';
        $propose = $casePath.'/'.$case['token'].'/propuesta';
        $choice = ['pesos' => [40,60], 'version' => $case['version'], 'motivo' => '40 % de las competencias se evaluaron en A y 60 % en B.'];
        $this->postJson($approve, $choice)->assertUnprocessable();
        $this->postJson($casePath.'/1/aprobar', $choice)->assertNotFound();
        $foreign = app(\App\Services\TenantProvisioner::class)->provision(['name' => 'Otro colegio', 'slug' => 'otro-consolidacion',
            'plan' => 'premium', 'rector_email' => 'rector@otro-consolidacion.test'])['tenant'];
        $foreignToken = $foreign->run(fn () => OpaqueUrlToken::for('matricula', $enrollment->id));
        $this->getJson($this->base.'/matriculas/'.$foreignToken.'/consolidaciones')->assertNotFound();
        $this->getJson($this->base.'/matriculas/1/consolidaciones')->assertNotFound();
        $this->putJson($sheet($destination), ['notas' => [$grade($before,'5')]])->assertUnprocessable();
        $this->putJson($sheet($destination), ['notas' => [$grade($b1,'4'), $grade($b2,'2')]])->assertOk();
        $this->postJson($approve, $choice)->assertConflict();
        $this->getJson($sheet($destination))->assertOk()->assertJsonPath('data.resultados.0.origen', 'pendiente_consolidacion')
            ->assertJsonPath('data.resultados.0.intervalos_grupo.0.desde', $cut->toDateString());
        $report = $this->base.'/evaluacion/boletines/'.$token;
        $this->getJson($report)->assertOk()->assertJsonPath('data.asignaturas.0.periodos.2.estado','pendiente');
        $current = $this->getJson($casePath)->assertOk()->json('casos.0');
        $this->assertSame(['Laboratorio','Sustentación'], array_column($current['tramos'][1]['evidencias'], 'nombre'));
        $this->assertSame('3.5', $current['tramos'][1]['subtotal']);
        $choice['version'] = $current['version'];
        $this->postJson($propose, [...$choice,'pesos' => [40,30]])->assertUnprocessable();
        $this->postJson($propose, $choice)->assertOk()->assertJsonPath('definitiva', '3.8');
        if ($withSupport) {
            $supportPath = $casePath.'/'.$case['token'].'/apoyos';
            $activityToken = OpaqueUrlToken::for('actividad-evaluacion', $before->id);
            $this->assertSame([$activityToken], array_column($current['actividades_disponibles'], 'actividad_token'));
            $payload = ['actividad_token' => $activityToken, 'motivo' => 'Reforzar las competencias del grupo de destino.',
                'fecha_entrega' => $cut->copy()->addWeeks(2)->toDateString()];
            $peer = User::create(['name' => 'Zeta Zulu', 'email' => 'zeta@example.test', 'role' => 'estudiante',
                'status' => 'active', 'password' => 'Testing123!']);
            $peerEnrollment = Matricula::create(['ano_lectivo_id' => $year->id, 'grupo_id' => $group->id, 'estudiante_id' => $peer->id, 'estado' => 'activa']);
            $peerEnrollment->created_at = $year->fecha_inicio;
            $peerEnrollment->save();
            $peerToken = OpaqueUrlToken::for('matricula', $peerEnrollment->id);
            $this->postJson($this->base.'/matriculas/'.$peerToken.'/correcciones', $movement)->assertNoContent();
            $this->postJson($this->base.'/matriculas/'.$peerToken.'/consolidaciones/'.$case['token'].'/apoyos', $payload)->assertNotFound();
            app('auth')->forgetGuards();
            $this->withToken($student->createToken('test')->plainTextToken)->postJson($supportPath, $payload)->assertForbidden();
            app('auth')->forgetGuards();
            $this->withToken($this->staffToken)->postJson($supportPath, [...$payload,'motivo' => ''])->assertUnprocessable();
            $this->postJson($supportPath, [...$payload,'fecha_entrega' => $before->fecha])->assertUnprocessable();
            $this->postJson($supportPath, [...$payload,'actividad_token' => '1'])->assertNotFound();
            $this->postJson($supportPath, [...$payload,'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $a1->id)])->assertNotFound();
            $this->postJson($supportPath, [...$payload,'actividad_token' => $foreign->run(fn () => OpaqueUrlToken::for('actividad-evaluacion', $before->id))])->assertNotFound();
            $this->postJson($supportPath, $payload)->assertNoContent();
            $this->postJson($supportPath, $payload)->assertNoContent();
            $this->postJson($supportPath, [...$payload,'motivo' => 'Cambio'])->assertConflict();
            $this->assertSame(1, DB::table('audit_logs')->where('recurso','matricula_actividad_apoyo')->count());
            $pending = $this->getJson($casePath)->assertOk()->assertJsonCount(0,'casos.0.actividades_disponibles')->json('casos.0');
            $this->assertNull($pending['tramos'][1]['subtotal']);
            $this->postJson($approve, $choice)->assertConflict();
            $this->postJson($approve, [...$choice, 'version' => $pending['version']])->assertUnprocessable();
            $rows = collect($this->getJson($sheet($destination))->assertOk()->json('data.resultados'))->keyBy('matricula_token');
            $this->assertSame($activityToken, $rows[$token]['actividades_apoyo'][0]['actividad_token']);
            $this->assertSame([], $rows[$peerToken]['actividades_apoyo']);
            $this->putJson($sheet($destination), ['notas' => [[...$grade($before,'5'),'matricula_token' => $peerToken]]])->assertUnprocessable();
            $this->putJson($sheet($destination), ['notas' => [$grade($before,'5')]])->assertOk();
            $updated = $this->getJson($casePath)->assertOk()->json('casos.0');
            $this->assertSame('4.2', $updated['tramos'][0]['subtotal']);
            $this->assertSame('3.8', $updated['tramos'][1]['subtotal']);
            $support = collect($updated['tramos'][1]['evidencias'])->firstWhere('actividad_token', $activityToken);
            $this->assertSame($payload['motivo'], $support['apoyo']['motivo']);
            $this->assertSame($payload['fecha_entrega'], $support['apoyo']['fecha_entrega']);
            $this->assertSame($rector->name, $support['apoyo']['responsable']);
            $choice['version'] = $updated['version'];
            $this->postJson($propose, $choice)->assertOk()->assertJsonPath('definitiva','4.0')->assertJsonPath('sin_apoyos','3.8');
        }
        $this->postJson($approve, [...$choice,'motivo' => ''])->assertUnprocessable();
        app('auth')->forgetGuards();
        $this->withToken($student->createToken('test')->plainTextToken)->postJson($approve, $choice)->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($this->staffToken)->postJson($approve, $choice)->assertNoContent();
        $this->postJson($approve, $choice)->assertNoContent();
        $this->postJson($approve, [...$choice, 'pesos' => [30,70]])->assertConflict();
        $this->putJson($sheet($destination), ['notas' => [$grade($b1,'3',1)]])->assertUnprocessable();
        $this->assertSame(1, DB::table('audit_logs')->where('recurso', 'matricula_consolidacion_periodo')->where('accion','APPROVE')->count());
        $this->getJson($sheet($destination))->assertOk()->assertJsonPath('data.resultados.0.display_value', $withSupport ? '4' : '3.8')
            ->assertJsonPath('data.resultados.0.origen','consolidacion')->assertJsonCount($withSupport ? 3 : 2,'data.calificaciones');
        if ($withSupport) {
            $this->postJson($supportPath, $payload)->assertConflict();
        }
        $this->getJson($casePath)->assertOk()->assertJsonPath('casos.0.aprobacion.responsable', $rector->name);
        $fourth = $this->localMathActivity($destination, $periods[3]);
        Calificacion::create(['actividad_id' => $fourth->id, 'matricula_id' => $enrollment->id, 'valor' => '4', 'updated_by' => $rector->id]);
        $final = $this->getJson($report)->assertOk()->assertJsonCount(4,'data.asignaturas.0.anual.trace.inputs')
            ->assertJsonPath('data.asignaturas.0.anual.display_value','4.1')->json('data.asignaturas.0.periodos');
        $this->assertSame(['4','4.5',$withSupport ? '4' : '3.8','4'], array_column($final, 'display_value'));
        $this->assertSame($withSupport ? '99/25' : '189/50', $final[2]['exact_value']);
        $this->assertEquals($withSupport ? [3,5,5,4,2] : [3,5,4,2], Calificacion::whereIn('actividad_id', [$a1->id,$a2->id,$later->id,$before->id,$b1->id,$b2->id])
            ->orderBy('actividad_id')->pluck('valor')->map(fn ($v) => (float) $v)->all());
        $rector->givePermissionTo(['boletines.observaciones.gestionar','boletines.emisiones.preparar','boletines.emisiones.ver']);
        $selection = ['contexto' => 'periodo', 'periodo_token' => $periodToken];
        $this->putJson($this->base.'/boletines/observaciones/'.$token, [...$selection, 'version' => 0,
            'contenido' => 'Se revisaron las actividades de ambos grupos.'])->assertOk();
        $period->update(['estado' => 'cerrado']);
        $this->postJson($this->base.'/boletines/emisiones/'.$token.'/emitir', $selection)->assertOk();
        $subject = BoletinEmision::sole()->snapshot['asignaturas'][0];
        $this->assertCount(1, $subject['periodos_reconocidos']);
        $this->assertSame('consolidacion', $subject['resultado']['origen']);
    }

    private function localMathActivity(AsignacionDocente $assignment, Periodo $period): ActividadEvaluacion
    {
        $component = ComponenteEvaluacion::create(['asignacion_id' => $assignment->id, 'periodo_id' => $period->id,
            'nombre' => 'Actividades', 'es_directo' => true, 'modo' => 'SIMPLE_AVERAGE', 'peso' => 100]);
        return ActividadEvaluacion::create(['componente_id' => $component->id, 'nombre' => 'Taller de Matemáticas', 'fecha' => $period->fecha_inicio]);
    }

    private function verifyRecognizedMathYear(Matricula $enrollment, AsignacionDocente $assignment, Collection $periods,
        ActividadEvaluacion $historical, User $rector, AnoLectivo $year, string $reportPath): void
    {
        $studentToken = OpaqueUrlToken::for('matricula', $enrollment->id);
        $sheet = fn ($period) => $this->base.'/evaluacion/planillas/'.OpaqueUrlToken::for('asignacion-docente', $assignment->id)
            .'/'.OpaqueUrlToken::for('periodo', $period->id);
        $row = fn ($activity, $value) => ['notas' => [['matricula_token' => $studentToken,
            'actividad_token' => OpaqueUrlToken::for('actividad-evaluacion', $activity->id), 'valor' => $value, 'version' => 0]]];
        foreach ([0 => '4', 1 => '4.5'] as $i => $value) {
            $list = $this->getJson($sheet($periods[$i]))->assertOk()->json('data.resultados');
            $studentResult = collect($list)->firstWhere('matricula_token', $studentToken);
            $this->assertSame('externa', $studentResult['origen']);
            $this->assertSame($value, $studentResult['display_value']);
            $this->assertSame([], $studentResult['secciones']);
            if ($i === 1) {
                $classmate = collect($list)->firstWhere('matricula_token', '!=', $studentToken);
                $this->assertSame('pendiente', $classmate['estado']);
                $this->assertNotSame('externa', $classmate['origen'] ?? null);
            }
        }
        $this->getJson($reportPath)->assertOk()->assertJsonPath('data.asignaturas.0.anual.estado', 'pendiente');
        $periods[0]->update(['estado' => 'abierto']);
        $this->putJson($sheet($periods[0]), $row($historical, '2'))->assertUnprocessable()->assertJsonPath('message',
            'La planilla contiene una definitiva externa aprobada. Las actividades de ese periodo no aplican al estudiante.');
        $periods[0]->update(['estado' => 'cerrado']);
        $this->assertEquals(1, Calificacion::where('matricula_id', $enrollment->id)->where('actividad_id', $historical->id)->sole()->valor);

        $issuePath = $this->base.'/boletines/emisiones/';
        $issue = function ($data) use ($issuePath, $studentToken) {
            $response = $this->postJson($issuePath.$studentToken.'/emitir', $data);
            $this->assertSame(200, $response->status(), $response->getContent());
            return $response->json('data.token');
        };
        foreach (['boletines.observaciones.gestionar','boletines.emisiones.preparar','boletines.emisiones.ver'] as $permission) {
            $rector->givePermissionTo($permission);
        }
        $issued = [];
        foreach ([0 => '4.0', 1 => '4.5'] as $i => $value) {
            $selection = ['contexto' => 'periodo', 'periodo_token' => OpaqueUrlToken::for('periodo', $periods[$i]->id)];
            $periods[$i]->update(['estado' => 'abierto']);
            $this->putJson($this->base.'/boletines/observaciones/'.$studentToken, [...$selection, 'version' => 0,
                'contenido' => 'Se reconoció la definitiva certificada de Matemáticas.'])->assertOk();
            $periods[$i]->update(['estado' => 'cerrado']);
            $token = $issue($selection);
            $record = BoletinEmision::where('contexto', 'periodo:'.$periods[$i]->id)->sole();
            $this->assertCount(1, $record->snapshot['asignaturas']);
            $subject = $record->snapshot['asignaturas'][0];
            $this->assertSame($value, $subject['resultado']['display_value']);
            $this->assertSame('externa', $subject['resultado']['origen']);
            $this->assertCount(1, $subject['periodos_reconocidos']);
            $bytes = $this->get($issuePath.'documentos/'.$token.'/pdf')->assertOk()->getContent();
            $this->assertStringStartsWith('%PDF-', $bytes);
            $issued[$record->id] = hash('sha256', $bytes);
        }
        foreach ([2 => '3.5', 3 => '4.8'] as $i => $value) {
            $periods[$i]->update(['estado' => 'abierto']);
            $activity = $this->localMathActivity($assignment, $periods[$i]);
            $this->putJson($sheet($periods[$i]), $row($activity, $value))->assertOk();
            $periods[$i]->update(['estado' => 'cerrado']);
        }
        $report = $this->getJson($reportPath)->assertOk()->assertJsonCount(1, 'data.asignaturas')->json('data.asignaturas.0');
        $this->assertSame(['4','4.5','3.5','4.8'], array_column($report['periodos'], 'display_value'));
        $this->assertSame('4.2', $report['anual']['display_value']);
        $this->assertCount(4, $report['anual']['trace']['inputs']);
        $this->assertCount(2, collect($report['periodos'])->where('origen', 'externa'));
        $this->assertSame(3, Calificacion::where('matricula_id', $enrollment->id)->count());

        $simpleSettings = $year->siee;
        $weights = $periods->pluck('peso');
        foreach ($periods as $i => $period) {
            $period->update(['peso' => ($i + 1) * 10]);
        }
        $year->update(['siee' => [...$simpleSettings, 'modo_anual' => 'WEIGHTED_AVERAGE']]);
        $this->getJson($reportPath)->assertOk()->assertJsonPath('data.asignaturas.0.anual.display_value', '4.3')
            ->assertJsonCount(4, 'data.asignaturas.0.anual.trace.inputs');
        $year->update(['siee' => $simpleSettings]);
        foreach ($periods as $i => $period) {
            $period->update(['peso' => $weights[$i]]);
        }

        $target = Grado::create(['ano_lectivo_id' => $year->id, 'nivel_id' => $enrollment->grupo->grado->nivel_id, 'nombre' => 'Segundo', 'estado' => 'activo']);
        $promotions = app(AcademicPromotionService::class);
        $promotions->savePolicy($rector, $year, 0, [], null, 0);
        $proposal = $promotions->proposal($year->fresh(), $enrollment);
        $this->assertSame('promovido', $proposal['resultado']);
        $promotions->approve($rector, $year, $enrollment, $proposal['huella'], 'promovido', $target, 'Resultados revisados.', 0);
        $year->update(['estado' => 'cerrado']);
        $token = $issue(['contexto' => 'final']);
        $final = BoletinEmision::where('contexto', 'final')->sole();
        $this->assertCount(1, $final->snapshot['asignaturas']);
        $subject = $final->snapshot['asignaturas'][0];
        $this->assertSame('4.2', $subject['resultado']['display_value']);
        $this->assertSame(['Primero','Segundo'], array_column($subject['periodos_reconocidos'], 'periodo'));
        $this->assertStringStartsWith('%PDF-', $this->get($issuePath.'documentos/'.$token.'/pdf')->assertOk()->getContent());
        foreach ($issued as $id => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('tenant')->get(BoletinEmision::findOrFail($id)->pdf_path)));
        }
    }
}
