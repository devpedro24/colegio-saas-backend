<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Process\Process;

final class BulletinCanvasRenderer
{
    public function layout(array $snapshot): array
    {
        $runtime = $snapshot['canvas_runtime'];
        $doc = $snapshot['plantilla']['configuracion']['canvas'];
        abort_unless(collect($doc['pages'])->contains(fn ($page) => ! ($page['hidden'] ?? false)), 422,
            __('El boletín no contiene páginas visibles.'));
        $doc['name'] = $snapshot['plantilla']['nombre'];
        $input = json_encode(['doc' => $doc, 'runtime' => $runtime,
            'institution' => $runtime['institution'], 'assets' => $runtime['assets']], JSON_THROW_ON_ERROR);
        abort_if(strlen($input) > 16_000_000, 422, __('El boletín supera el tamaño permitido.'));
        // Executable and script are deployment configuration, never request values.
        $process = new Process([config('bulletins.node_binary', 'node'), '--max-old-space-size=192', resource_path('bulletin-editor/render.cjs')]);
        $process->setInput($input)->setTimeout((float) config('bulletins.layout_timeout', 20));
        $process->run();
        abort_unless($process->isSuccessful() && strlen($process->getOutput()) <= 8_000_000, 422,
            __('No se pudo distribuir el boletín. Revisa el tamaño de los bloques y las columnas de la plantilla.'));
        $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($output['pages'] ?? 0) >= 1 && $output['pages'] <= 60, 422, __('El boletín supera el límite de páginas.'));

        return $output;
    }

    public function render(array $snapshot): string
    {
        $output = $this->layout($snapshot);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('pdfBackend', 'CPDF');
        $options->set('chroot', [resource_path('bulletin-editor/fonts'), storage_path('fonts')]);
        if (! is_dir(storage_path('fonts'))) {
            mkdir(storage_path('fonts'), 0755, true);
        }
        $options->set('fontDir', storage_path('fonts'));
        $options->set('fontCache', storage_path('fonts'));
        $pdf = new Dompdf($options);
        $doc = $snapshot['plantilla']['configuracion']['canvas'];
        $fonts = collect($doc['pages'])->flatMap(fn ($page) => $page['blocks'])->flatMap(fn ($node) => [$node['font'], $node['table']['headerFont'] ?? $node['font']])
            ->merge([$doc['sheet']['footer']['font'], $doc['sheet']['numbering']['font']])->unique();
        $manifest = json_decode(file_get_contents(resource_path('bulletin-editor/fonts/catalog/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $paths = collect($manifest)->mapWithKeys(fn ($font) => [$font['family'] => resource_path('bulletin-editor/fonts/catalog/'.$font['key'])])->all();
        $paths['Inter'] = resource_path('bulletin-editor/fonts');
        foreach ($fonts as $family) {
            if (! isset($paths[$family])) {
                continue;
            }
            foreach (['normal', 'bold'] as $weight) {
                foreach (['normal', 'italic'] as $style) {
                    $fontPath = $paths[$family].'/pdf-'.($weight === 'bold' ? 'bold' : 'regular').($style === 'italic' ? '-italic' : '').'.ttf';
                    abort_unless($pdf->getFontMetrics()->registerFont(['family' => $family, 'weight' => $weight, 'style' => $style], $fontPath), 500, 'No se pudo cargar la tipografía del boletín.');
                }
            }
        }
        // Dompdf ignores named @page sizes. Lay out the pre-paginated wrappers in
        // a common envelope, then give each CPDF page its own box and top origin.
        $pageSizes = $output['pageSizes'];
        $pdf->setPaper([0, 0, max(array_column($pageSizes, 'width')) * 0.75, max(array_column($pageSizes, 'height')) * 0.75]);
        $pdf->setCallbacks([
            ['event' => 'begin_page_render', 'f' => static function ($frame, CPDF $canvas) use ($pageSizes): void {
                $size = $pageSizes[$canvas->get_page_number() - 1] ?? null;
                abort_unless($size, 422, __('La paginación del PDF no coincide con el boletín.'));
                $width = $size['width'] * 0.75;
                $height = $size['height'] * 0.75;
                $cpdf = $canvas->get_cpdf();
                $cpdf->objects[$cpdf->currentPage]['info']['mediaBox'] = [0, 0, $width, $height];
                $canvas->save();
                $cpdf->transform([1, 0, 0, 1, 0, $height - $canvas->get_height()]);
            }],
            ['event' => 'end_page_render', 'f' => static function ($frame, CPDF $canvas): void {
                $canvas->restore();
            }],
        ]);
        $pdf->loadHtml($output['html'], 'UTF-8');
        $pdf->render();
        abort_unless($pdf->getCanvas()->get_page_count() === count($pageSizes), 422,
            __('La paginación del PDF no coincide con el boletín.'));
        $bytes = $pdf->output();
        abort_unless(str_starts_with($bytes, '%PDF-'), 500);

        return $bytes;
    }
}
