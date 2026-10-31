<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\EnsureOnboardingComplete;
use App\Models\Academico\BoletinEmision;
use App\Models\Academico\BoletinPlantilla;
use App\Models\Academico\EscalaOpcion;
use App\Models\Academico\EscalaValorativa;
use App\Models\Academico\Matricula;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BulletinArchive;
use App\Services\BulletinCanvasRenderer;
use App\Services\BulletinIssueService;
use App\Services\BulletinPerformance;
use App\Services\BulletinTemplateDesign;
use App\Services\GradebookService;
use App\Services\TenantOnboarding;
use App\Support\OpaqueUrlToken;
use App\Support\Storage\FileScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait BulletinTemplateTests
{
    private function canvasInput(): array
    {
        $input = $this->templateInput();
        $input['configuracion']['canvas'] = ['key' => 'test-design', 'format' => 'a4', 'pages' => [['blocks' => [
            ['key' => 'student', 'type' => 'text', 'text' => '{{student}} · {{period}}', 'x' => 44, 'y' => 44, 'w' => 700, 'h' => 35],
            ['key' => 'results', 'type' => 'table', 'x' => 44, 'y' => 100, 'w' => 700, 'h' => 140,
                'table' => ['columns' => [['key' => 'subject', 'label' => 'Asignatura', 'width' => 60], ['key' => 'grade', 'label' => 'Nota', 'width' => 40]]]],
            ['key' => 'observation', 'type' => 'observation', 'text' => '{{observation}}', 'x' => 44, 'y' => 265, 'w' => 700, 'h' => 60],
        ]]]];

        return $input;
    }

    private function canvasSnapshot(array $pages): array
    {
        $input = $this->canvasInput();
        $input['configuracion']['canvas']['pages'] = $pages;

        return ['plantilla' => ['nombre' => 'Geometría de páginas',
            'configuracion' => BulletinTemplateDesign::validate($input)['configuracion']],
            'canvas_runtime' => ['actual' => true, 'fields' => ['school' => 'Colegio de prueba'],
                'rows' => [], 'areaRows' => [], 'visual' => false, 'periodCount' => 1,
                'scaleRows' => [], 'icons' => [], 'institution' => ['name' => 'Colegio de prueba'], 'assets' => []]];
    }

    public function test_template_catalog_provides_the_current_institutional_logo(): void
    {
        Storage::fake('tenant');
        $api = $this->templateManagerApi();
        $this->getJson($api)->assertOk()->assertJsonPath('data.institucion.logo', '');
        $logo = UploadedFile::fake()->image('logo.png', 240, 120)->get();
        Storage::disk('tenant')->put(TenantOnboarding::logoPath(), $logo);
        $this->getJson($api)->assertOk()->assertJsonPath('data.institucion.logo', 'data:image/png;base64,'.base64_encode($logo));
    }

    public function test_renaming_a_template_syncs_readable_archive_folders_and_preserves_every_pdf(): void
    {
        Storage::fake('tenant');
        Storage::fake('local');
        [$period, $enrollment] = $this->gradeFixture();
        $api = $this->templateManagerApi();
        $input = $this->templateInput();
        $input['nombre'] = 'Notas numéricas';
        $template = $this->postJson($api, $input)->assertCreated()->json('data');
        $url = 'http://localhost/api/boletines/plantillas/'.$template['token'];
        $this->postJson($url.'/publicar?opaque=1', [...$input, 'version' => 1])->assertOk();
        $model = BoletinPlantilla::firstOrFail();
        $homonym = User::create(['name' => 'Homónimo de prueba', 'email' => 'homonym-archive@test.test',
            'password' => 'StudentPassword123', 'role' => 'estudiante', 'status' => 'active']);
        $secondEnrollment = Matricula::create(['estudiante_id' => $homonym->id, 'grupo_id' => $this->group->id,
            'ano_lectivo_id' => $this->year->id, 'estado' => 'activa']);
        $snapshot = ['estudiante' => ['nombre' => 'Salomé Álvarez Arias', 'grado' => 'Primero', 'grupo' => '01A'],
            'periodo' => ['nombre' => 'Cuarto período'], 'ano' => '2026', 'plantilla' => ['nombre' => $input['nombre']]];
        $rows = [];
        foreach ([[$enrollment, 1], [$enrollment, 2], [$secondEnrollment, 1]] as [$student, $revision]) {
            $bytes = '%PDF archived '.$student->id.' revision '.$revision;
            $row = BoletinEmision::create(['matricula_id' => $student->id, 'contexto' => 'periodo:'.$period->id,
                'periodo_id' => $period->id, 'revision' => $revision, 'estado' => 'aprobado', 'snapshot' => $snapshot,
                'plantilla_id' => $model->id, 'plantilla_revision' => 1, 'huella_insumos' => hash('sha256', 'inputs'),
                'prepared_by' => $this->rector->id, 'approved_by' => $this->rector->id, 'pdf_sha256' => hash('sha256', $bytes)]);
            $path = BulletinArchive::write($row, $bytes);
            $row->update(['pdf_path' => $path]);
            $this->assertStringContainsString('/Revisión_'.$revision.'_Notas_numéricas', $path);
            $this->assertStringNotContainsString(OpaqueUrlToken::for('boletin-emision', $row->id), $path);
            $rows[] = [$row, $path, $bytes];
        }
        $this->assertStringContainsString('_Copia_2/', $rows[2][1]);
        $this->assertNotSame($rows[0][1], $rows[2][1]);
        $input['nombre'] = 'Caritas e indicadores';
        $this->putJson($url.'?opaque=1', [...$input, 'version' => 2])->assertOk();
        foreach ($rows as [$row, $old, $bytes]) {
            $current = $row->fresh();
            $this->assertStringContainsString('/Revisión_'.$row->revision.'_Caritas_e_indicadores', $current->pdf_path);
            $this->assertSame($current->pdf_path, BulletinArchive::path($current));
            $this->assertSame($bytes, Storage::disk('tenant')->get($current->pdf_path));
            $this->assertSame($snapshot, $current->snapshot);
            $this->assertSame(1, $current->plantilla_revision);
            $this->assertSame($bytes, Storage::disk('local')->get('bulletin-migration-backups/'.$old));
            Storage::disk('tenant')->assertMissing($old);
        }
        $this->assertStringContainsString('_Copia_2/', $rows[2][0]->fresh()->pdf_path);
        $input['nombre'] = 'Boletín definitivo';
        $this->postJson($url.'/publicar?opaque=1', [...$input, 'version' => 3])->assertOk();
        foreach ($rows as [$row, , $bytes]) {
            $current = $row->fresh();
            $this->assertStringContainsString('/Revisión_'.$row->revision.'_Boletín_definitivo', $current->pdf_path);
            $this->assertSame($bytes, Storage::disk('tenant')->get($current->pdf_path));
            $this->assertSame($snapshot, $current->snapshot);
        }
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.emisiones.ver', 'web'));
        app('auth')->forgetGuards();
        $this->getJson('http://localhost/api/boletines/emisiones/documentos/'.
            OpaqueUrlToken::for('boletin-emision', $rows[0][0]->id).'/pdf?opaque=1')->assertOk()
            ->assertContent($rows[0][2]);
        $this->artisan('storage:organize-bulletins', ['--tenant' => $this->school->slug])->expectsOutput('0 archivos por organizar (sin aplicar).')->assertExitCode(0);
    }

    public function test_template_preview_valuation_survives_saving_and_publishing_without_sample_data(): void
    {
        $api = $this->templateManagerApi();
        $input = $this->canvasInput();
        $input['configuracion']['canvas']['design'] = ['previewScale' => 'visual'];
        $input['configuracion']['canvas']['sample'] = ['student' => 'Sample data must not persist'];
        $created = $this->postJson($api, $input)->assertCreated()
            ->assertJsonPath('data.configuracion.canvas.design.previewScale', 'visual')
            ->assertJsonMissingPath('data.configuracion.canvas.sample')->json('data');
        $this->postJson('http://localhost/api/boletines/plantillas/'.$created['token'].'/publicar?opaque=1', [...$input, 'version' => 1])
            ->assertOk()->assertJsonPath('data.configuracion.canvas.design.previewScale', 'visual');
    }

    public function test_canvas_page_metadata_persists_in_drafts_and_immutable_revisions(): void
    {
        $api = $this->templateManagerApi();
        $input = $this->canvasInput();
        $canvas = &$input['configuracion']['canvas'];
        $canvas['format'] = 'oficio';
        $canvas['orientation'] = 'landscape';
        $canvas['pages'][0]['title'] = '';
        $canvas['pages'][] = ['title' => str_repeat('á', 100), 'hidden' => true, 'locked' => true,
            'format' => 'a5', 'orientation' => 'portrait', 'blocks' => [
                ['key' => 'private-note', 'type' => 'text', 'text' => 'Nota de diseño oculta.'],
            ]];
        $created = $this->postJson($api, $input)->assertCreated()
            ->assertJsonPath('data.configuracion.canvas.pages.0.title', '')
            ->assertJsonPath('data.configuracion.canvas.pages.0.format', 'oficio')
            ->assertJsonPath('data.configuracion.canvas.pages.0.orientation', 'landscape')
            ->assertJsonPath('data.configuracion.canvas.pages.0.hidden', false)
            ->assertJsonPath('data.configuracion.canvas.pages.0.locked', false)
            ->assertJsonPath('data.configuracion.canvas.pages.1.title', str_repeat('á', 100))
            ->assertJsonPath('data.configuracion.canvas.pages.1.hidden', true)
            ->assertJsonPath('data.configuracion.canvas.pages.1.locked', true)
            ->assertJsonPath('data.configuracion.canvas.pages.1.format', 'a5')
            ->assertJsonPath('data.configuracion.canvas.pages.1.orientation', 'portrait')->json('data');
        $url = 'http://localhost/api/boletines/plantillas/'.$created['token'];
        $this->postJson($url.'/publicar?opaque=1', [...$input, 'version' => 1])->assertOk();
        $canvas['pages'][1]['title'] = 'Anexo visible';
        $canvas['pages'][1]['hidden'] = false;
        $canvas['pages'][1]['locked'] = false;
        $canvas['pages'][1]['format'] = 'carta';
        $this->putJson($url.'?opaque=1', [...$input, 'version' => 2])->assertOk();
        $this->getJson($url.'?opaque=1')->assertOk()
            ->assertJsonPath('data.configuracion.canvas.pages.1.title', 'Anexo visible')
            ->assertJsonPath('data.configuracion.canvas.pages.1.hidden', false)
            ->assertJsonPath('data.configuracion.canvas.pages.1.locked', false)
            ->assertJsonPath('data.configuracion.canvas.pages.1.format', 'carta');
        $this->getJson($url.'/revisiones?opaque=1')->assertOk()
            ->assertJsonPath('data.0.configuracion.canvas.pages.1.title', str_repeat('á', 100))
            ->assertJsonPath('data.0.configuracion.canvas.pages.1.hidden', true)
            ->assertJsonPath('data.0.configuracion.canvas.pages.1.locked', true)
            ->assertJsonPath('data.0.configuracion.canvas.pages.1.format', 'a5')
            ->assertJsonPath('data.0.configuracion.canvas.pages.1.blocks.0.text', 'Nota de diseño oculta.');
    }

    public function test_canvas_rejects_invalid_page_metadata_without_saving(): void
    {
        $api = $this->templateManagerApi();
        foreach ([['title', str_repeat('á', 101)], ['title', 123], ['title', []],
            ['hidden', 'false'], ['hidden', 1], ['hidden', []], ['hidden', null],
            ['locked', 'true'], ['locked', 0], ['locked', []], ['locked', null],
            ['format', 'a3'], ['format', []], ['format', null],
            ['orientation', 'horizontal'], ['orientation', []], ['orientation', null]] as [$key, $value]) {
            $input = $this->canvasInput();
            $input['configuracion']['canvas']['pages'][0][$key] = $value;
            $this->postJson($api, $input)->assertUnprocessable();
        }
        $this->assertDatabaseCount('boletin_plantillas', 0);
    }

    public function test_canvas_all_hidden_pages_can_be_saved_but_cannot_be_printed(): void
    {
        $input = $this->canvasInput();
        $input['configuracion']['canvas']['pages'][0]['hidden'] = true;
        $this->postJson($this->templateManagerApi(), $input)->assertCreated()
            ->assertJsonPath('data.configuracion.canvas.pages.0.hidden', true);
        $snapshot = $this->canvasSnapshot($input['configuracion']['canvas']['pages']);
        try {
            app(BulletinCanvasRenderer::class)->render($snapshot);
            $this->fail('A PDF needs at least one visible page.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
            $this->assertSame('El boletín no contiene páginas visibles.', $error->getMessage());
        }
    }

    public function test_canvas_print_excludes_hidden_pages_blocks_and_repeating_headers(): void
    {
        $snapshot = $this->canvasSnapshot([
            ['hidden' => true, 'format' => 'oficio', 'orientation' => 'landscape', 'blocks' => [
                ['key' => 'secret-header', 'type' => 'header', 'repeat' => true,
                    'content' => ['headerTitle' => 'SECRET REPEATING HEADER', 'headerBody' => 'SECRET HEADER BODY']],
                ['key' => 'secret-page', 'type' => 'text', 'text' => 'SECRET PAGE CONTENT'],
            ]],
            ['locked' => true, 'format' => 'a5', 'orientation' => 'landscape', 'blocks' => [
                ['key' => 'visible-note', 'type' => 'text', 'text' => 'VISIBLE LOCKED PAGE', 'y' => 44],
                ['key' => 'secret-block', 'type' => 'text', 'text' => 'SECRET BLOCK CONTENT', 'hidden' => true],
            ]],
            ['blocks' => [['key' => 'another-note', 'type' => 'text', 'text' => 'SECOND VISIBLE PAGE']]],
        ]);
        $output = app(BulletinCanvasRenderer::class)->layout($snapshot);
        $this->assertSame(2, $output['pages']);
        $this->assertSame([['width' => 794, 'height' => 559], ['width' => 794, 'height' => 1123]], $output['pageSizes']);
        $this->assertStringContainsString('VISIBLE LOCKED PAGE', $output['html']);
        $this->assertStringContainsString('SECOND VISIBLE PAGE', $output['html']);
        $this->assertStringNotContainsString('SECRET', $output['html']);
        $this->assertStringContainsString('Página 1 de 2', $output['html']);
        $this->assertStringContainsString('Página 2 de 2', $output['html']);
    }

    public function test_canvas_pdf_has_actual_mixed_media_boxes_and_page_geometry(): void
    {
        $pages = [];
        $expectedSizes = [];
        foreach (['a4' => [794, 1123], 'carta' => [816, 1056], 'oficio' => [816, 1344], 'a5' => [559, 794]] as $format => [$w, $h]) {
            foreach (['portrait', 'landscape'] as $orientation) {
                $index = count($pages);
                $width = $orientation === 'portrait' ? $w : $h;
                $height = $orientation === 'portrait' ? $h : $w;
                $pages[] = ['format' => $format, 'orientation' => $orientation, 'blocks' => [
                    ['key' => 'page-label-'.$index, 'type' => 'text', 'text' => 'PAGE '.$index.' '.$format.' '.$orientation],
                    ['key' => 'page-bottom-'.$index, 'type' => 'text', 'text' => 'BOTTOM PAGE '.$index,
                        'x' => $width - 284, 'y' => $height - 160, 'w' => 240, 'h' => 30],
                ]];
                $expectedSizes[] = ['width' => $width, 'height' => $height];
            }
        }
        array_splice($pages, 2, 0, [['hidden' => true, 'blocks' => [
            ['key' => 'hidden-pdf-page', 'type' => 'text', 'text' => 'HIDDEN PDF PAGE'],
        ]]]);
        $snapshot = $this->canvasSnapshot($pages);
        $renderer = app(BulletinCanvasRenderer::class);
        $output = $renderer->layout($snapshot);
        $this->assertSame($expectedSizes, $output['pageSizes']);
        foreach ($expectedSizes as $index => $size) {
            $this->assertStringContainsString('@page bulletinPage'.$index.'{size:'.$size['width'].'px '.$size['height'].'px;margin:0}', $output['html']);
            $this->assertStringContainsString('page:bulletinPage'.$index.';width:'.$size['width'].'px;height:'.$size['height'].'px', $output['html']);
        }
        $pdf = $renderer->render($snapshot);
        preg_match_all('~/Type /Page\b(?:(?!endobj).)*?/MediaBox\s*\[([^\]]+)\]~s', $pdf, $matches);
        $this->assertCount(count($expectedSizes), $matches[1], 'Check actual PDF pages, excluding the parent /Pages dictionary.');
        foreach ($matches[1] as $index => $box) {
            $dimensions = array_map('floatval', preg_split('/\s+/', trim($box)));
            $this->assertEqualsWithDelta(0, $dimensions[0], 0.001);
            $this->assertEqualsWithDelta(0, $dimensions[1], 0.001);
            $this->assertEqualsWithDelta($expectedSizes[$index]['width'] * 0.75, $dimensions[2], 0.001);
            $this->assertEqualsWithDelta($expectedSizes[$index]['height'] * 0.75, $dimensions[3], 0.001);
        }
        if (getenv('BULLETIN_QA_MIXED_PDF')) {
            file_put_contents(getenv('BULLETIN_QA_MIXED_PDF'), $pdf);
        }
    }

    public function test_canvas_continuation_pages_keep_their_source_geometry(): void
    {
        $snapshot = $this->canvasSnapshot([
            ['format' => 'a5', 'orientation' => 'portrait', 'blocks' => [
                ['key' => 'long-results', 'type' => 'table', 'w' => 470, 'y' => 44],
            ]],
            ['format' => 'oficio', 'orientation' => 'landscape', 'blocks' => [
                ['key' => 'last-page', 'type' => 'text', 'text' => 'END OF DOCUMENT'],
            ]],
        ]);
        $rows = array_map(fn ($i) => ['key' => 'row-'.$i, 'subject' => 'Asignatura '.$i, 'area' => '', 'hours' => 2,
            'display' => '4.0', 'performance' => '', 'indicators' => [], 'periodDisplay' => ['4.0']], range(1, 75));
        $snapshot['canvas_runtime']['rows'] = $rows;
        $renderer = app(BulletinCanvasRenderer::class);
        $output = $renderer->layout($snapshot);
        $this->assertGreaterThan(2, $output['pages']);
        $this->assertSame(['width' => 1344, 'height' => 816], $output['pageSizes'][$output['pages'] - 1]);
        foreach (array_slice($output['pageSizes'], 0, -1) as $size) {
            $this->assertSame(['width' => 559, 'height' => 794], $size);
        }
        foreach ($rows as $row) {
            $this->assertSame(1, substr_count($output['html'], 'data-row-key="'.$row['key'].'"'));
        }
        $pdf = $renderer->render($snapshot);
        $this->assertSame($output['pages'], preg_match_all('~/Type /Page\b~', $pdf));
    }

    public function test_canvas_print_uses_each_nodes_appearance(): void
    {
        $snapshot = $this->canvasSnapshot([['blocks' => [
            ['key' => 'local-header', 'type' => 'header', 'appearance' => ['header' => 'band', 'headerInk' => '#FEDCBA']],
            ['key' => 'local-table', 'type' => 'table', 'y' => 300, 'appearance' => ['table' => 'striped']],
        ]]]);
        $html = app(BulletinCanvasRenderer::class)->layout($snapshot)['html'];
        $this->assertStringContainsString('color:#FEDCBA;padding:15px', $html);
        $this->assertStringContainsString('tbody tr:nth-child(even) td{background:#f5f1fb}', $html);
    }

    public function test_canvas_runtime_only_requires_areas_and_performance_for_visible_content(): void
    {
        Storage::fake('tenant');
        [$period, $enrollment, $activity] = $this->gradeFixture();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        DB::table('observaciones_boletin')->insert(['matricula_id' => $enrollment->id, 'contexto' => 'periodo:'.$period->id,
            'contenido' => 'Avanza con interés.', 'version' => 1, 'updated_by' => $this->teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        $input = $this->canvasInput();
        $input['configuracion']['canvas']['pages'][] = ['hidden' => true, 'blocks' => [
            ['key' => 'optional-results', 'type' => 'table', 'table' => ['rowMode' => 'areas',
                'columns' => [['key' => 'performance', 'label' => 'Desempeño', 'width' => 100]]]],
        ]];
        $build = function (array $input) use ($period, $enrollment): array {
            return app(BulletinIssueService::class)->build($enrollment, 'periodo', $period,
                ['nombre' => 'Páginas opcionales', 'configuracion' => BulletinTemplateDesign::validate($input)['configuracion']])['snapshot'];
        };
        $snapshot = $build($input);
        $this->assertSame('4.5', $snapshot['canvas_runtime']['rows'][0]['display']);
        $this->assertSame('Trimestre 1', $snapshot['canvas_runtime']['fields']['period']);
        $this->assertSame('periodo', $snapshot['plantilla']['configuracion']['canvas']['scope']);
        $this->assertTrue($snapshot['plantilla']['configuracion']['canvas']['pages'][1]['hidden']);
        $this->assertFalse((bool) $this->year->fresh()->siee['usar_areas']);
        $this->assertEmpty(EscalaValorativa::findOrFail($this->year->siee['escala_id'])->desempenos);
        $input['configuracion']['canvas']['pages'][1]['hidden'] = false;
        $input['configuracion']['canvas']['pages'][1]['blocks'][0]['hidden'] = true;
        $this->assertSame('4.5', $build($input)['canvas_runtime']['rows'][0]['display']);
        $input['configuracion']['canvas']['pages'][1]['blocks'][0]['hidden'] = false;
        foreach (['performance', 'areas'] as $requirement) {
            $invalid = $input;
            $invalid['configuracion']['canvas']['pages'][1]['blocks'][0]['table'] = $requirement === 'performance'
                ? ['rowMode' => 'subjects', 'columns' => [['key' => 'performance', 'label' => 'Desempeño', 'width' => 100]]]
                : ['rowMode' => 'areas'];
            try {
                $build($invalid);
                $this->fail('Visible content must still require its SIEE configuration.');
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
                $this->assertStringContainsString($requirement === 'performance' ? 'desempeño' : 'área', $error->getMessage());
            }
        }
        $this->assertSame('4.5', $snapshot['canvas_runtime']['rows'][0]['display']);
    }

    public function test_canvas_assets_are_scanned_private_and_scoped_to_the_school(): void
    {
        Storage::fake('tenant');
        config(['storage.disk' => 'tenant']);
        foreach (['boletines.plantillas.ver', 'boletines.plantillas.gestionar'] as $permission) {
            $this->rector->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Plan::updateOrCreate(['key' => 'estandar'], ['name' => 'Estándar', 'features' => ['academico', 'boletines_personalizables']]);
        $this->school->update(['plan' => 'estandar']);
        $scanner = \Mockery::mock(FileScanner::class);
        $scanner->shouldReceive('scan')->once();
        $this->app->instance(FileScanner::class, $scanner);
        $this->templateApi($this->rector);
        $base = 'http://localhost/api/boletines/plantillas/archivos';
        $response = $this->post($base.'?opaque=1', ['archivo' => UploadedFile::fake()->image('escudo.png', 80, 80)])
            ->assertCreated()->assertJsonPath('data.mime', 'image/png');
        $data = $response->json('data');
        $this->assertEqualsCanonicalizing(['token', 'mime', 'bytes'], array_keys($data));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $data['token']);
        $this->getJson($base.'/'.$data['token'].'?opaque=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($base.'/1?opaque=1')->assertNotFound();
        $file = StoredFile::where('public_token', $data['token'])->firstOrFail();
        $other = Tenant::withoutEvents(fn () => Tenant::create([
            'id' => (string) Str::uuid(), 'slug' => 'other-asset-school', 'name' => 'Otro colegio',
        ]));
        $file->update(['tenant_id' => $other->id]);
        $this->getJson($base.'/'.$data['token'].'?opaque=1')->assertNotFound();
        $input = $this->canvasInput();
        $input['configuracion']['canvas']['assets'] = ['crest' => $data['token']];
        $this->postJson('http://localhost/api/boletines/plantillas?opaque=1', $input)->assertUnprocessable();
        $this->templateApi($this->teacher);
        $this->getJson($base.'/'.$data['token'].'?opaque=1')->assertForbidden();
        $this->post($base.'?opaque=1', ['archivo' => UploadedFile::fake()->image('otro.png')])->assertForbidden();
    }

    public function test_siee_performance_bands_require_permission_cover_the_scale_and_lock_closed_years(): void
    {
        $this->gradeFixture();
        $this->rector->givePermissionTo(Permission::findOrCreate('academico.configurar', 'web'));
        $this->templateApi($this->rector);
        $scale = EscalaValorativa::where('tipo', 'numerica')->firstOrFail();
        $url = 'http://localhost/api/config/escalas/'.OpaqueUrlToken::for('escala-valorativa', $scale->id).'?opaque=1';
        $input = ['ano_lectivo_token' => OpaqueUrlToken::for('ano-lectivo', $this->year->id),
            'nombre' => $scale->nombre, 'nivel_educativo' => null, 'tipo' => 'numerica', 'valor_min' => 0, 'valor_max' => 5, 'decimales' => 1,
            'desempenos' => [['nombre' => 'Bajo', 'desde' => '0.0', 'hasta' => '2.9'], ['nombre' => 'Alcanzado', 'desde' => '3.0', 'hasta' => '5.0']]];
        $before = $this->year->fresh()->siee;
        $this->putJson($url, $input)->assertOk()->assertJsonPath('data.desempenos.1.nombre', 'Alcanzado');
        $this->assertSame($before, $this->year->fresh()->siee);
        $invalid = $input;
        $invalid['desempenos'][1]['desde'] = '3.1';
        $this->putJson($url, $invalid)->assertUnprocessable();
        $changedBounds = $input;
        unset($changedBounds['desempenos']);
        $changedBounds['valor_max'] = 6;
        $this->putJson($url, $changedBounds)->assertUnprocessable();
        $this->year->update(['estado' => 'cerrado']);
        $this->putJson($url, $input)->assertUnprocessable();
        $this->templateApi($this->teacher);
        $this->putJson($url, $input)->assertForbidden();
    }

    public function test_canvas_api_discards_fake_runtime_and_preserves_published_designs(): void
    {
        foreach (['boletines.plantillas.ver', 'boletines.plantillas.gestionar'] as $permission) {
            $this->rector->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Plan::updateOrCreate(['key' => 'estandar'], ['name' => 'Estándar', 'features' => ['academico', 'boletines_personalizables']]);
        $this->school->update(['plan' => 'estandar']);
        $api = $this->templateApi($this->rector);
        $input = $this->canvasInput();
        $input['configuracion']['canvas']['runtime'] = ['student' => 'Fake', 'grade' => '5.0'];
        $input['configuracion']['canvas']['sample'] = ['student' => 'Fake'];
        $input['configuracion']['canvas']['sheet'] = ['footer' => ['height' => 84], 'numbering' => ['height' => 42]];
        $input['configuracion']['canvas']['pages'][0]['blocks'][0]['groupKey'] = 'school-header-group';
        $input['configuracion']['canvas']['pages'][0]['blocks'][0]['appearance'] = ['header' => 'band', 'table' => 'striped', 'signature' => 'rector', 'headerInk' => '#FFFFFF'];
        $input['configuracion']['canvas']['pages'][0]['blocks'][] = [
            'key' => 'school-logo', 'type' => 'image', 'source' => 'school-logo',
            'groupKey' => 'school-header-group', 'headerPart' => true, 'repeat' => true,
            'x' => 650, 'y' => 44, 'w' => 60, 'h' => 60,
        ];
        $created = $this->postJson($api, $input)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('runtime', $created['configuracion']['canvas']);
        $this->assertArrayNotHasKey('sample', $created['configuracion']['canvas']);
        $this->assertEquals(84, $created['configuracion']['canvas']['sheet']['footer']['height']);
        $this->assertEquals(42, $created['configuracion']['canvas']['sheet']['numbering']['height']);
        $this->assertSame('school-header-group', $created['configuracion']['canvas']['pages'][0]['blocks'][0]['groupKey']);
        $this->assertSame('band', $created['configuracion']['canvas']['pages'][0]['blocks'][0]['appearance']['header']);
        $this->assertSame('rector', $created['configuracion']['canvas']['pages'][0]['blocks'][0]['appearance']['signature']);
        $this->assertSame('school-logo', $created['configuracion']['canvas']['pages'][0]['blocks'][3]['source']);
        $url = 'http://localhost/api/boletines/plantillas/'.$created['token'];
        $this->postJson($url.'/publicar?opaque=1', [...$input, 'version' => 1])->assertOk();
        $this->getJson($url.'/revisiones?opaque=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.configuracion.canvas.pages.0.blocks.0.text', '{{student}} · {{period}}')
            ->assertJsonPath('data.0.configuracion.canvas.pages.0.blocks.3.headerPart', true)
            ->assertJsonPath('data.0.configuracion.canvas.pages.0.blocks.3.source', 'school-logo')
            ->assertJsonPath('data.0.configuracion.canvas.sheet.footer.height', 84);
        $invalidGroup = $input;
        $invalidGroup['configuracion']['canvas']['pages'][0]['blocks'][0]['groupKey'] = '../other-school';
        $this->putJson($url.'?opaque=1', [...$invalidGroup, 'version' => 2])->assertUnprocessable();
        $input['configuracion']['canvas']['pages'][0]['blocks'][0]['font'] = "x';url(file:///etc/passwd)";
        $this->putJson($url.'?opaque=1', [...$input, 'version' => 2])->assertUnprocessable();
        $this->assertDatabaseCount('boletin_plantilla_revisiones', 1);
    }

    public function test_canvas_emission_uses_frozen_siee_results_and_generates_pdf(): void
    {
        Storage::fake('tenant');
        [$period, $enrollment, $activity] = $this->gradeFixture();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        DB::table('observaciones_boletin')->insert(['matricula_id' => $enrollment->id, 'contexto' => 'periodo:'.$period->id,
            'contenido' => 'Avanza con interés.', 'version' => 1, 'updated_by' => $this->teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        $config = BulletinTemplateDesign::validate($this->canvasInput())['configuracion'];
        $service = app(BulletinIssueService::class);
        $snapshot = $service->build($enrollment, 'periodo', $period, ['nombre' => 'Canvas', 'configuracion' => $config])['snapshot'];
        $this->assertSame('4.5', $snapshot['canvas_runtime']['rows'][0]['display']);
        $this->assertSame('Estudiante', $snapshot['canvas_runtime']['fields']['student']);
        $output = app(BulletinCanvasRenderer::class)->layout($snapshot);
        $this->assertStringContainsString('4.5', $output['html']);
        $this->assertStringNotContainsString('Lucía Torres', $output['html']);
        $this->assertSame(1, $output['pages']);
        $this->assertStringStartsWith('%PDF-', $service->render($snapshot, null));
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '2.5', 'version' => 1,
        ]]);
        $this->assertSame('4.5', $snapshot['canvas_runtime']['rows'][0]['display']);
        $this->assertStringContainsString('4.5', app(BulletinCanvasRenderer::class)->layout($snapshot)['html']);
    }

    public function test_canvas_uses_visual_categories_and_configured_numeric_performance(): void
    {
        Storage::fake('tenant');
        [$period, $enrollment, $activity] = $this->gradeFixture();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        DB::table('observaciones_boletin')->insert(['matricula_id' => $enrollment->id, 'contexto' => 'periodo:'.$period->id,
            'contenido' => 'Avanza con interés.', 'version' => 1, 'updated_by' => $this->teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        $input = $this->canvasInput();
        $input['configuracion']['canvas']['pages'][0]['blocks'][1]['table']['columns'][] = ['key' => 'performance', 'label' => 'Desempeño', 'width' => 30];
        $config = BulletinTemplateDesign::validate($input)['configuracion'];
        $service = app(BulletinIssueService::class);
        $scale = EscalaValorativa::where('tipo', 'numerica')->firstOrFail();
        $scale->update(['desempenos' => BulletinPerformance::validate([
            ['nombre' => 'Por reforzar', 'desde' => '0', 'hasta' => '2.9'], ['nombre' => 'Alcanzado', 'desde' => '3', 'hasta' => '5'],
        ], '0', '5')]);
        $snapshot = $service->build($enrollment, 'periodo', $period, ['nombre' => 'Canvas', 'configuracion' => $config])['snapshot'];
        $this->assertSame('Alcanzado', $snapshot['canvas_runtime']['rows'][0]['performance']);
        $this->assertTrue($snapshot['canvas_runtime']['rows'][0]['approved']);
        $visual = EscalaValorativa::create(['ano_lectivo_id' => $this->year->id,
            'nivel_educativo' => $this->group->grado->nivel->nivel_educativo, 'tipo' => 'imagenes', 'nombre' => 'Caritas']);
        foreach ([['Bien', '5', '🙂'], ['En proceso', '2', '😐']] as $i => [$name, $grade, $emoji]) {
            EscalaOpcion::create(['escala_id' => $visual->id, 'nombre' => $name, 'orden' => $i + 1,
                'valor_equivalente' => $grade, 'emoji' => $emoji, 'aprueba' => $i === 0]);
        }
        $snapshot = $service->build($enrollment, 'periodo', $period, ['nombre' => 'Canvas', 'configuracion' => $config])['snapshot'];
        $this->assertSame('Bien', $snapshot['canvas_runtime']['rows'][0]['display']);
        $this->assertSame('🙂', $snapshot['canvas_runtime']['rows'][0]['emoji']);
        $html = app(BulletinCanvasRenderer::class)->layout($snapshot)['html'];
        $this->assertStringContainsString('Bien', $html);
        $this->assertStringNotContainsString('4.5', $html);
        if (getenv('BULLETIN_QA_VISUAL')) {
            file_put_contents(getenv('BULLETIN_QA_VISUAL'), $service->render($snapshot, null));
            file_put_contents(getenv('BULLETIN_QA_VISUAL').'.html', $html);
            $icon = array_values($snapshot['canvas_runtime']['icons'])[0];
            file_put_contents(getenv('BULLETIN_QA_VISUAL').'.png', base64_decode(explode(',', $icon)[1]));
        }
    }

    public function test_canvas_paginates_every_row_and_repeats_headers(): void
    {
        $config = BulletinTemplateDesign::validate($this->canvasInput())['configuracion'];
        $rows = array_map(fn ($i) => ['key' => 'row-'.$i, 'subject' => 'Asignatura '.$i, 'area' => '', 'hours' => 2,
            'display' => '4.0', 'performance' => '', 'indicators' => [], 'periodDisplay' => ['4.0']], range(1, 75));
        $snapshot = ['plantilla' => ['nombre' => 'Paginación', 'configuracion' => $config], 'canvas_runtime' => [
            'actual' => true, 'fields' => ['student' => 'Estudiante de prueba', 'period' => 'Primer período', 'observation' => 'Fin del documento.'],
            'rows' => $rows, 'areaRows' => [], 'visual' => false, 'periodCount' => 1, 'scaleRows' => [], 'icons' => [],
            'institution' => ['name' => 'Colegio de prueba'], 'assets' => [],
        ]];
        $service = app(BulletinCanvasRenderer::class);
        $output = $service->layout($snapshot);
        $this->assertGreaterThan(1, $output['pages']);
        foreach ($rows as $row) {
            $this->assertSame(1, substr_count($output['html'], 'data-row-key="'.$row['key'].'"'));
        }
        $pdf = $service->render($snapshot);
        $this->assertStringStartsWith('%PDF-', $pdf);
        if (getenv('BULLETIN_QA_PDF')) {
            file_put_contents(getenv('BULLETIN_QA_PDF'), $pdf);
            file_put_contents(getenv('BULLETIN_QA_PDF').'.html', $output['html']);
        }
    }

    private function templateApi(User $user): string
    {
        $this->withoutMiddleware(EnsureOnboardingComplete::class);
        app('auth')->forgetGuards();
        $this->withHeader('X-Tenant', $this->school->id)
            ->withToken($user->createToken('test-bulletin-template')->plainTextToken);

        return 'http://localhost/api/boletines/plantillas?opaque=1';
    }

    private function templateManagerApi(): string
    {
        foreach (['boletines.plantillas.ver', 'boletines.plantillas.gestionar'] as $permission) {
            $this->rector->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Plan::updateOrCreate(['key' => 'estandar'], ['name' => 'Estándar', 'features' => ['academico', 'boletines_personalizables']]);
        $this->school->update(['plan' => 'estandar']);

        return $this->templateApi($this->rector);
    }

    public function test_template_deletion_requires_scope_permission_plan_and_current_version(): void
    {
        $api = $this->templateManagerApi();
        $created = $this->postJson($api, $this->canvasInput())->assertCreated()->json('data');
        $path = 'http://localhost/api/boletines/plantillas/'.$created['token'];
        $item = BoletinPlantilla::firstOrFail();
        $this->postJson($path.'/publicar?opaque=1', [...$this->canvasInput(), 'version' => 1])->assertOk();
        $this->deleteJson($path.'?opaque=1')->assertUnprocessable();
        $this->deleteJson($path.'?opaque=1&version=1')->assertConflict();
        $this->deleteJson('http://localhost/api/boletines/plantillas/'.$item->id.'?opaque=1&version=2')->assertNotFound();
        $original = tenancy()->tenant;
        tenancy()->tenant = new Tenant(['id' => (string) Str::uuid(), 'slug' => 'foreign-template']);
        try {
            $foreign = OpaqueUrlToken::for('boletin-plantilla', $item->id);
        } finally {
            tenancy()->tenant = $original;
        }
        $this->deleteJson('http://localhost/api/boletines/plantillas/'.$foreign.'?opaque=1&version=2')->assertNotFound();
        $this->templateApi($this->teacher);
        $this->deleteJson($path.'?opaque=1&version=2')->assertForbidden();
        $this->templateApi($this->rector);
        Plan::updateOrCreate(['key' => 'esencial'], ['name' => 'Esencial', 'features' => ['academico']]);
        $this->school->update(['plan' => 'esencial']);
        $this->deleteJson($path.'?opaque=1&version=2')->assertForbidden();
        $this->school->update(['plan' => 'estandar']);
        $this->deleteJson($path.'?opaque=1&version=2')->assertOk()->assertExactJson(['data' => ['eliminada' => true]]);
        $this->assertSoftDeleted('boletin_plantillas', ['id' => $item->id]);
        $this->getJson($api)->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.plantillas');
        $this->getJson($path.'?opaque=1')->assertNotFound();
        $this->getJson($path.'/revisiones?opaque=1')->assertNotFound();
        $this->putJson($path.'?opaque=1', [...$this->canvasInput(), 'version' => 3])->assertNotFound();
        $this->postJson($path.'/publicar?opaque=1', [...$this->canvasInput(), 'version' => 3])->assertNotFound();
        $this->deleteJson($path.'?opaque=1&version=3')->assertNotFound();
        $this->assertDatabaseCount('boletin_plantilla_revisiones', 1);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'DELETE', 'recurso' => 'boletin_plantilla', 'recurso_id' => (string) $item->id]);
    }

    public function test_template_deletion_preserves_issue_foreign_keys_and_frozen_pdf(): void
    {
        Storage::fake('tenant');
        [$period, $enrollment, $activity] = $this->gradeFixture();
        app(GradebookService::class)->saveGrades($this->teacher, $this->assignment, $period->id, [[
            'actividad_id' => $activity->id, 'matricula_id' => $enrollment->id, 'valor' => '4.5', 'version' => 0,
        ]]);
        DB::table('observaciones_boletin')->insert(['matricula_id' => $enrollment->id, 'contexto' => 'periodo:'.$period->id,
            'contenido' => 'Avanza con interés.', 'version' => 1, 'updated_by' => $this->teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        $api = $this->templateManagerApi();
        $created = $this->postJson($api, $this->canvasInput())->assertCreated()->json('data');
        $path = 'http://localhost/api/boletines/plantillas/'.$created['token'];
        $this->postJson($path.'/publicar?opaque=1', [...$this->canvasInput(), 'version' => 1])->assertOk();
        $service = app(BulletinIssueService::class);
        $template = $service->template('periodo', $created['token']);
        $snapshot = $service->build($enrollment, 'periodo', $period, $template)['snapshot'];
        $issueId = DB::table('boletin_emisiones')->insertGetId(['matricula_id' => $enrollment->id,
            'contexto' => 'periodo:'.$period->id, 'periodo_id' => $period->id, 'revision' => 1, 'estado' => 'preparado',
            'plantilla_id' => $template['id'], 'plantilla_revision' => 1, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'huella_insumos' => str_repeat('a', 64), 'prepared_by' => $this->rector->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->deleteJson($path.'?opaque=1&version=2')->assertOk();
        $issue = DB::table('boletin_emisiones')->where('id', $issueId)->first();
        $this->assertSame($template['id'], $issue->plantilla_id);
        $this->assertSame($snapshot, json_decode($issue->snapshot, true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('boletin_plantilla_revisiones', 1);
        $this->assertStringContainsString('4.5', app(BulletinCanvasRenderer::class)->layout($snapshot)['html']);
        $this->assertStringStartsWith('%PDF-', $service->render($snapshot, null));
        try {
            $service->template('periodo', $created['token']);
            $this->fail('A deleted template must not be selectable for a new issue.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
    }

    private function templateInput(string $scope = 'periodo'): array
    {
        return ['nombre' => 'Diseño institucional', 'ambito' => $scope,
            'configuracion' => BulletinTemplateDesign::base($scope)];
    }

    public function test_template_catalog_has_no_virtual_base_and_editing_requires_feature_and_permission(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.ver', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.gestionar', 'web'));
        Plan::updateOrCreate(['key' => 'esencial'], ['name' => 'Esencial', 'features' => ['academico']]);
        $this->school->update(['plan' => 'esencial']);
        $api = $this->templateApi($this->rector);
        $this->getJson($api)->assertOk()->assertJsonPath('data.colegio', 'Colegio')
            ->assertJsonPath('data.puede_editar', false)
            ->assertJsonMissingPath('data.base');
        $this->postJson($api, $this->templateInput())->assertForbidden();
        $this->templateApi($this->teacher);
        $this->getJson($api)->assertForbidden();
    }

    public function test_template_picker_shows_the_published_revision_instead_of_later_draft_edits(): void
    {
        $api = $this->templateManagerApi();
        $input = $this->canvasInput();
        $created = $this->postJson($api, $input)->assertCreated()->json('data');
        $path = 'http://localhost/api/boletines/plantillas/'.$created['token'];
        $this->postJson($path.'/publicar?opaque=1', [...$input, 'version' => 1])->assertOk();
        $draft = $input;
        $draft['nombre'] = 'Unpublished draft changes';
        $draft['configuracion']['heading'] = 'This must not be previewed';
        $this->putJson($path.'?opaque=1', [...$draft, 'version' => 2])->assertOk();
        $this->getJson($api.'&publicadas=1&ambito=periodo')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.plantillas.0.nombre', $input['nombre'])
            ->assertJsonPath('data.plantillas.0.configuracion.heading', $input['configuracion']['heading']);
        $this->getJson($api.'&publicadas=1&ambito=final')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($api.'&publicadas=1&ambito=unknown')->assertUnprocessable();
    }

    public function test_template_preview_catalog_uses_the_current_siee_ranges_even_when_a_period_is_closed(): void
    {
        [$period] = $this->gradeFixture();
        $this->year->update(['estado' => 'en_curso']);
        $period->update(['estado' => 'cerrado']);
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.ver', 'web'));
        $api = $this->templateApi($this->rector);
        $this->getJson($api)->assertOk()->assertJsonPath('data.institucion.performanceRanges', [])
            ->assertJsonPath('data.institucion.scaleLegend', '');
        $ranges = BulletinPerformance::validate([
            ['nombre' => 'Inicial', 'desde' => '0', 'hasta' => '3.9'],
            ['nombre' => 'Avanzado', 'desde' => '4', 'hasta' => '5'],
        ], '0', '5');
        EscalaValorativa::findOrFail($this->year->fresh()->siee['escala_id'])->update(['desempenos' => $ranges]);
        $this->getJson($api)->assertOk()->assertJsonPath('data.institucion.performanceRanges', $ranges)
            ->assertJsonPath('data.institucion.scaleLegend', implode("\n", BulletinPerformance::legend($ranges)));
        $this->assertSame('cerrado', $period->fresh()->estado);
    }

    public function test_bulletin_template_live_design_publishes_immutable_revisions_without_emitting_a_report(): void
    {
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.ver', 'web'));
        $this->rector->givePermissionTo(Permission::findOrCreate('boletines.plantillas.gestionar', 'web'));
        Plan::updateOrCreate(['key' => 'estandar'], ['name' => 'Estándar',
            'features' => ['academico', 'boletines_personalizables']]);
        $this->school->update(['plan' => 'estandar']);
        $api = $this->templateApi($this->rector);
        $this->getJson($api)->assertOk()->assertJsonPath('data.puede_editar', true);
        $invalid = $this->templateInput();
        $invalid['configuracion']['sections'][2]['key'] = 'student';
        $this->postJson($api, $invalid)->assertUnprocessable();
        $invalid = $this->templateInput();
        $invalid['configuracion']['heading'] = '<script>malicioso</script>';
        $this->postJson($api, $invalid)->assertUnprocessable();
        $created = $this->postJson($api, $this->templateInput())
            ->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.revision_publicada', null)->json('data');
        $path = 'http://localhost/api/boletines/plantillas/'.$created['token'].'?opaque=1';
        $first = $this->templateInput();
        $first['configuracion']['accent'] = '#812A60';
        $this->postJson('http://localhost/api/boletines/plantillas/'.$created['token'].'/publicar?opaque=1',
            [...$first, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.revision_publicada', 1);
        $changed = $this->templateInput();
        $changed['configuracion']['accent'] = '#167A70';
        $this->putJson($path, [...$changed, 'version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->assertDatabaseHas('boletin_plantilla_revisiones', ['revision' => 1]);
        $this->assertSame('#812A60', json_decode(DB::table('boletin_plantilla_revisiones')
            ->where('revision', 1)->value('configuracion'), true)['accent']);
        $this->putJson($path, [...$changed, 'version' => 2])->assertConflict();
        $this->postJson('http://localhost/api/boletines/plantillas/'.$created['token'].'/publicar?opaque=1',
            [...$changed, 'version' => 3])->assertOk()->assertJsonPath('data.revision_publicada', 2);
        $this->assertDatabaseCount('boletin_plantilla_revisiones', 2);
        $this->assertDatabaseCount('boletin_plantillas', 1);
        $this->getJson($path)->assertOk()->assertJsonPath('data.configuracion.accent', '#167A70');
        $this->assertDatabaseHas('audit_logs', ['recurso' => 'boletin_plantilla', 'accion' => 'PUBLISH']);
        $this->assertDatabaseMissing('audit_logs', ['recurso' => 'boletin_emitido']);
        $this->school->update(['plan' => 'esencial']);
        Plan::updateOrCreate(['key' => 'esencial'], ['name' => 'Esencial', 'features' => ['academico']]);
        $this->getJson($api)->assertOk()->assertJsonPath('data.puede_editar', false);
        $this->putJson($path, [...$changed, 'version' => 4])->assertForbidden();
    }
}
