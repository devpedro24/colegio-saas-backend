<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/** Reads visible pixels, never a PDF's hidden text layer. Not an authenticity check. */
class IdentityDocumentReader
{
    public function read(string $path, string $mime): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enrollment-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            abort(503, __('intake.ocrUnavailable'));
        }
        try {
            $images = [$path];
            if ($mime === 'application/pdf') {
                $info = $this->run([config('enrollment.pdfinfo'), $path], 15);
                if (! preg_match('/Pages:\s+(\d+)/', $info, $pages) || (int) $pages[1] > config('enrollment.max_identity_pages')) {
                    throw ValidationException::withMessages(['documentos' => __('intake.identityPages')]);
                }
                $this->run([config('enrollment.pdftoppm'), '-f', '1', '-l', $pages[1], '-scale-to', '2600', '-png', $path, $directory.'/page'], 40);
                $images = glob($directory.'/page-*.png');
                sort($images, SORT_NATURAL);
            } elseif (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
                throw ValidationException::withMessages(['documentos' => __('intake.identityFormat')]);
            } else {
                $size = @getimagesize($path);
                if (! $size || $size[0] * $size[1] > 20000000) {
                    throw ValidationException::withMessages(['documentos' => __('intake.identityUnreadable')]);
                }
            }
            if (! $images) {
                throw new \RuntimeException('No rendered pages');
            }
            $output = $this->run([config('enrollment.node'), '--max-old-space-size=512', base_path('scripts/enrollment-ocr.mjs'), ...$images], 90);

            return json_decode($output, true, flags: JSON_THROW_ON_ERROR)['text'];
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            // Process exceptions can contain identity text/paths: never log them.
            abort(503, __('intake.ocrUnavailable'));
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function run(array $command, int $seconds): string
    {
        $process = new Process($command, base_path(), ['OMP_THREAD_LIMIT' => '1']);
        $process->setTimeout($seconds)->mustRun();
        if (strlen($process->getOutput()) > 200000) {
            throw new \RuntimeException('Output too large');
        }

        return $process->getOutput();
    }

    public function validate(string $text, array $data): void
    {
        $text = Str::upper(Str::ascii($text));
        $flat = preg_replace('/\s+/', ' ', $text);
        // Only the labelled date of birth is accepted, not issue/expiration dates.
        if (! preg_match('/(?:FECHA\s+DE\s+NACIMIENTO|DATE\s+OF\s+BIRTH|NACIMIENTO|BIRTH)\s*[:.\-]?\s*(\d{1,4})[\s.\/\-]+([A-Z]+|\d{1,2})[\s.\/\-]+(\d{2,4})/', $flat, $date)) {
            throw ValidationException::withMessages(['documentos' => __('intake.identityUnreadable')]);
        }
        $months = ['ENE' => 1, 'JAN' => 1, 'ENERO' => 1, 'FEB' => 2, 'FEBRERO' => 2, 'MAR' => 3, 'MARZO' => 3, 'ABR' => 4, 'APR' => 4, 'ABRIL' => 4, 'MAY' => 5, 'MAYO' => 5, 'JUN' => 6, 'JUNIO' => 6, 'JUL' => 7, 'JULIO' => 7, 'AGO' => 8, 'AUG' => 8, 'AGOSTO' => 8, 'SEP' => 9, 'SEPT' => 9, 'SEPTIEMBRE' => 9, 'OCT' => 10, 'OCTUBRE' => 10, 'NOV' => 11, 'NOVIEMBRE' => 11, 'DIC' => 12, 'DEC' => 12, 'DICIEMBRE' => 12];
        $month = ctype_digit($date[2]) ? (int) $date[2] : ($months[$date[2]] ?? 0);
        $yearFirst = strlen($date[1]) === 4;
        $year = (int) ($yearFirst ? $date[1] : $date[3]);
        $day = (int) ($yearFirst ? $date[3] : $date[1]);
        if (! checkdate($month, $day, $year)) {
            throw ValidationException::withMessages(['documentos' => __('intake.identityUnreadable')]);
        }
        $errors = [];
        if (sprintf('%04d-%02d-%02d', $year, $month, $day) !== $data['nacimiento']) {
            $errors['datos.nacimiento'] = __('intake.identityBirthMismatch');
        }
        // Restrict number matching to labelled number fields; strip punctuation only.
        preg_match_all('/(?:NUMERO(?:\s+DE\s+DOCUMENTO)?|NUMBER|NUIP|NIP|DOCUMENTO?\s+(?:NO|NRO))[\s:.*#-]*([0-9][0-9. -]{2,39}|[A-Z]{1,3}[0-9][A-Z0-9-]{2,36})\b/', $flat, $numbers);
        $wanted = preg_replace('/[^A-Z0-9]/', '', Str::upper($data['numero_documento']));
        $numberFound = false;
        foreach ($numbers[1] as $number) {
            $candidate = preg_replace('/[^A-Z0-9]/', '', trim($number));
            // OCR line flattening may capture the following label; require exact numeric token.
            if ($candidate === $wanted || (ctype_digit($wanted) && preg_match('/^([0-9. ]+)/', $number, $numeric) && preg_replace('/\D/', '', $numeric[1]) === $wanted)) {
                $numberFound = true;
            }
        }
        if (! $numberFound) {
            $errors['datos.numero_documento'] = __('intake.identityNumberMismatch');
        }
        foreach (['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'] as $key) {
            foreach (preg_split('/\s+/', trim(Str::upper(Str::ascii($data[$key] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (! preg_match('/\b'.preg_quote($word, '/').'\b/', $flat)) {
                    $errors['datos.'.$key] = __('intake.identityNameMismatch');
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
