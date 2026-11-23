<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

final class CertificateVerification
{
    public static function create(): array
    {
        $code = strtoupper(bin2hex(random_bytes(12)));
        $url = app(EnrollmentIntake::class)->baseUrl().'/verificar-constancia';
        $formatted = implode('-', str_split($code, 4));
        $qr = (new Writer(new GDLibRenderer(420, 4)))->writeString($url.'#'.$formatted, 'UTF-8', ErrorCorrectionLevel::M());

        return ['hash' => hash('sha256', $code), 'codigo' => $formatted, 'url' => $url, 'qr' => 'data:image/png;base64,'.base64_encode($qr)];
    }

    public static function lookup(string $input): ?array
    {
        $code = strtoupper(str_replace([' ', '-'], '', trim($input)));
        if (! preg_match('/^[0-9A-F]{24}$/D', $code)) {
            return null;
        }
        $record = DB::table(EarlyPromotionCertificates::TABLE.' as c')->join(EarlyPromotions::TABLE.' as p', 'p.id', '=', 'c.promocion_id')
            ->where('p.estado', 'aprobada')->whereNotNull('c.pdf_sha256')->where('c.codigo_verificacion_hash', hash('sha256', $code))
            ->first(['c.emisor_verificacion', 'c.consecutivo', 'c.fecha_expedicion']);

        return $record ? ['colegio' => $record->emisor_verificacion, 'numero' => EarlyPromotionCertificates::number($record->consecutivo),
            'fecha_expedicion' => $record->fecha_expedicion] : null;
    }

    /** Preserve every original page, including names and dates as they appeared when issued. */
    public static function appendToArchive(string $original, array $verification, string $number, string $school): string
    {
        $sheet = new Dompdf(new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false]));
        $sheet->setPaper('A4');
        $sheet->loadHtml(view('pdf.certificate-verification-annex', compact('verification', 'number', 'school'))->render(), 'UTF-8');
        $sheet->render();

        $copy = new Fpdi;
        foreach ([$original, $sheet->output()] as $bytes) {
            $pages = $copy->setSourceFile(StreamReader::createByString($bytes));
            for ($page = 1; $page <= $pages; $page++) {
                $template = $copy->importPage($page, importExternalLinks: true);
                $size = $copy->getTemplateSize($template);
                $copy->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $copy->useTemplate($template);
            }
        }

        return $copy->Output('S');
    }
}
