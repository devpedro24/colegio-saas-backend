<?php

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Plan;
use App\Models\User;
use App\Services\HorarioService;
use App\Support\OpaqueUrlToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

trait AttendanceCorrectionTests
{
    private function sameDayAttendanceFixture(): array
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        Plan::updateOrCreate(['key' => 'esencial'], ['name' => 'Esencial', 'features' => ['academico', 'asistencia']]);
        $this->teacher->givePermissionTo(Permission::findOrCreate('asistencia.registrar_clases', 'web'));
        $this->teacher->givePermissionTo(Permission::findOrCreate('asistencia.correccion.solicitar', 'web'));
        foreach (['asistencia.registrar_clases', 'asistencia.consultar_grupo', 'asistencia.correccion.aprobar'] as $key) {
            $this->rector->givePermissionTo(Permission::findOrCreate($key, 'web'));
        }
        [$period, $enrollment] = $this->gradeFixture();
        $session = app(HorarioService::class)->guardar(['asignacion_id' => $this->assignment->id,
            'dia' => 'lunes', 'bloque_horario_id' => $this->block->id], $this->rector);
        $this->attendanceActor($this->teacher);

        return [$period, [
            'asignacion_token' => OpaqueUrlToken::for('asignacion-docente', $this->assignment->id),
            'sesion_token' => OpaqueUrlToken::for('sesion-horario', $session->id),
            'fecha' => '2026-02-02', 'version' => 0,
            'marcas' => [['matricula_token' => OpaqueUrlToken::for('matricula', $enrollment->id), 'estado' => 'ausente']],
        ]];
    }

    private function attendanceActor(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Tenant', $this->school->id)->withToken($user->createToken('test')->plainTextToken);
    }

    public function test_attendance_same_day_present_correction_is_audited_and_preserves_authorization(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03T04:59:59Z'));
        try {
            [$period, $payload] = $this->sameDayAttendanceFixture();
            $url = 'http://localhost/api/asistencias?opaque=1';
            $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.version', 1);
            $payload['version'] = 1;
            $payload['marcas'][0]['estado'] = 'presente';

            $other = User::create(['name' => 'Otro docente', 'email' => 'other-attendance@test.invalid',
                'password' => 'TeacherPassword123', 'role' => 'docente', 'status' => 'active']);
            $other->assignRole('docente');
            $other->givePermissionTo(Permission::findOrCreate('asistencia.registrar_clases', 'web'));
            $this->attendanceActor($other);
            $this->putJson($url, $payload)->assertForbidden();
            $this->attendanceActor($this->teacher);
            $this->teacher->revokePermissionTo('asistencia.registrar_clases');
            $this->putJson($url, $payload)->assertForbidden();
            $this->teacher->givePermissionTo('asistencia.registrar_clases');
            $this->attendanceActor($this->teacher);
            $period->update(['estado' => 'cerrado']);
            $this->putJson($url, $payload)->assertUnprocessable();
            $period->update(['estado' => 'abierto']);
            $this->year->update(['estado' => 'cerrado']);
            $this->putJson($url, $payload)->assertUnprocessable();
            $this->year->update(['estado' => 'en_curso']);

            $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.version', 2);
            $this->assertDatabaseHas('asistencia_marcas', ['estado' => 'presente', 'registrado_por_id' => $this->teacher->id]);
            $audit = DB::table('audit_logs')->where('recurso', 'asistencia_marca')->where('accion', 'UPDATE')->first();
            $this->assertNotNull($audit);
            $this->assertEquals($this->teacher->id, $audit->actor_id);
            $this->assertSame('ausente', json_decode($audit->valor_previo, true)['estado']);
            $after = json_decode($audit->valor_nuevo, true);
            $this->assertSame('presente', $after['estado']);
            $this->assertSame('2026-02-02', $after['fecha']);
            $this->assertSame('America/Bogota', $after['zona_horaria']);
            $this->assertDatabaseCount('asistencia_solicitudes', 0);
            $this->putJson($url, $payload)->assertStatus(409);

            $payload['version'] = 2;
            $payload['marcas'][0]['estado'] = 'ausente';
            $this->putJson($url, $payload)->assertOk();
            $payload['version'] = 3;
            $payload['marcas'][0]['estado'] = 'sin_marcar';
            $this->putJson($url, $payload)->assertUnprocessable();
            $payload['marcas'][0]['estado'] = 'justificada';
            $this->putJson($url, $payload)->assertUnprocessable();
            $payload['marcas'][0]['estado'] = 'presente';
            $this->attendanceActor($this->rector);
            $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.version', 4);
            $this->assertDatabaseHas('asistencia_marcas', ['estado' => 'presente', 'registrado_por_id' => $this->rector->id]);
            $this->getJson($url.'&asignacion_token='.$payload['asignacion_token'].'&fecha=2026-02-02')
                ->assertOk()->assertJsonPath('data.clases.0.estudiantes.0.resumen.faltas_equivalentes', 0);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_attendance_correction_expires_at_colombia_midnight_and_past_absence_can_be_justified(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03T04:59:59Z'));
        try {
            [$period, $payload] = $this->sameDayAttendanceFixture();
            $url = 'http://localhost/api/asistencias?opaque=1';
            $this->putJson($url, $payload)->assertOk();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03T05:00:00Z'));
            $payload['version'] = 1;
            $payload['marcas'][0]['estado'] = 'presente';
            $this->putJson($url, $payload)->assertUnprocessable()
                ->assertJsonPath('message', 'Una ausencia guardada solo puede corregirse a Presente el mismo día de la clase (hora de Colombia). Para fechas pasadas, solicita una justificación y su aprobación.');
            $this->attendanceActor($this->rector);
            $this->putJson($url, $payload)->assertUnprocessable();
            $this->assertDatabaseHas('asistencia_marcas', ['estado' => 'ausente']);
            $this->assertDatabaseHas('asistencia_clases', ['version' => 1]);
            $this->assertSame(0, DB::table('audit_logs')->where('recurso', 'asistencia_marca')->where('accion', 'UPDATE')->count());

            $this->attendanceActor($this->teacher);
            $period->update(['estado' => 'cerrado']);
            $request = $this->postJson('http://localhost/api/asistencias/solicitudes?opaque=1', [
                'matricula_token' => $payload['marcas'][0]['matricula_token'],
                'asignacion_token' => $payload['asignacion_token'],
                'marcas' => [['sesion_token' => $payload['sesion_token'], 'fecha' => $payload['fecha']]],
                'motivo' => 'La inasistencia se marcó por error y se solicita justificar.',
            ])->assertCreated()->assertJsonPath('data.estado', 'pendiente_aprobacion');
            $resolution = 'http://localhost/api/asistencias/solicitudes/'.$request->json('data.token').'/resolucion?opaque=1';
            $this->putJson($resolution, ['decision' => 'aprobar'])->assertForbidden();
            $this->attendanceActor($this->rector);
            $this->putJson($resolution, ['decision' => 'aprobar'])->assertOk()->assertJsonPath('data.estado', 'aprobada');
            $this->assertDatabaseHas('asistencia_marcas', ['estado' => 'justificada']);
            $this->assertDatabaseHas('asistencia_clases', ['version' => 2]);
            $period->update(['estado' => 'abierto']);
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-03T04:59:59Z'));
            $payload['version'] = 2;
            $this->putJson($url, $payload)->assertUnprocessable();
            $this->assertDatabaseHas('asistencia_marcas', ['estado' => 'justificada']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
