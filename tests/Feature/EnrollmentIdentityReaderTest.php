<?php

namespace Tests\Feature;

use App\Services\Ingreso\IdentityDocumentReader;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnrollmentIdentityReaderTest extends TestCase
{
    private function student(): array
    {
        return ['primer_nombre' => 'Ana', 'primer_apellido' => 'Pruebas', 'nacimiento' => '2018-02-14', 'numero_documento' => '123456789'];
    }

    public function test_reader_matches_dob_not_issue_date_and_handles_accents(): void
    {
        $reader = new IdentityDocumentReader;
        $reader->validate("NÚMERO 123.456.789\nAPELLIDOS PRUEBAS\nNOMBRES ÁNA\nFECHA DE NACIMIENTO 14-FEB-2018\nFECHA DE EXPEDICIÓN 01-MAR-2025", $this->student());
        $this->addToAssertionCount(1);
        $this->expectException(ValidationException::class);
        $reader->validate('NUMERO 123456789 ANA PRUEBAS FECHA DE EXPEDICION 14-FEB-2018', $this->student());
    }

    public function test_real_offline_ocr_reads_png_and_rendered_pdf(): void
    {
        $reader = new IdentityDocumentReader;
        $directory = sys_get_temp_dir().'/enrollment-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $lines = ['DOCUMENTO DE PRUEBA - SIN VALIDEZ', 'NUMERO 123456789', 'APELLIDOS PRUEBAS', 'NOMBRES ANA', 'FECHA DE NACIMIENTO 14-FEB-2018'];
            $small = imagecreatetruecolor(600, 180);
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
            foreach ($lines as $i => $line) {
                imagestring($small, 5, 20, 20 + $i * 28, $line, imagecolorallocate($small, 0, 0, 0));
            }
            $large = imagescale($small, 1800, 540);
            imagepng($large, $directory.'/identity.png');
            imagejpeg($large, $directory.'/identity.jpg', 95);
            imagedestroy($small);
            imagedestroy($large);
            $reader->validate($reader->read($directory.'/identity.png', 'image/png'), $this->student());
            $reader->validate($reader->read($directory.'/identity.jpg', 'image/jpeg'), $this->student());
            // Minimal text PDF: it must be rasterized, then OCR'd (no hidden-text trust).
            $stream = 'BT /F1 16 Tf 40 740 Td '.implode(' 0 -35 Td ', array_map(fn ($line) => '('.$line.') Tj', $lines)).' ET';
            $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
                '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream"];
            $pdf = "%PDF-1.4\n";
            $offsets = [];
            foreach ($objects as $i => $object) {
                $offsets[] = strlen($pdf);
                $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
            }
            $xref = strlen($pdf);
            $pdf .= "xref\n0 6\n0000000000 65535 f \n";
            foreach ($offsets as $offset) {
                $pdf .= sprintf("%010d 00000 n \n", $offset);
            }
            $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
            file_put_contents($directory.'/identity.pdf', $pdf);
            $reader->validate($reader->read($directory.'/identity.pdf', 'application/pdf'), $this->student());
            $this->addToAssertionCount(3);
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
