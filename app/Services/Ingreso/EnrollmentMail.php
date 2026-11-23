<?php

namespace App\Services\Ingreso;

final class EnrollmentMail
{
    public function data(string $subject, string $body, string $noticeKey): array
    {
        $base = app(EnrollmentIntake::class)->baseUrl();
        $credentials = str_ends_with($noticeKey, ':credenciales');
        $actionUrl = $base.($credentials ? '/auth' : '/ingreso');
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $pin = null;
        $pinValidity = null;
        $validityPrefix = null;
        if (str_contains($noticeKey, ':pin')) {
            foreach ($lines as $line) {
                if (preg_match('/^Tu PIN es ([0-9]{4}|[0-9]{8})\./', trim($line), $match)) {
                    $pin = $match[1];
                }
                if (preg_match('/^Vence el ([0-9]{2}\/[0-9]{2}\/[0-9]{4}) ([0-9]{2}:[0-9]{2})(?: \(([^)]+)\))?\./', trim($line), $expiry)) {
                    $validityPrefix = $expiry[0];
                    $pinValidity = 'Válido hasta el '.$expiry[1].' a las '.$expiry[2]
                        .(! empty($expiry[3]) ? ' ('.$expiry[3].')' : '').'.';
                }
                if (str_starts_with($line, 'Enlace privado: ')) {
                    $candidate = trim(substr($line, strlen('Enlace privado: ')));
                    if (str_starts_with($candidate, $base.'/ingreso/') && preg_match('/#solicitud=[a-f0-9]{64}\z/', $candidate)) {
                        $actionUrl = $candidate;
                    }
                }
            }
        }
        if ($pin) {
            $lines = array_map(static function (string $line) use ($validityPrefix): string {
                $line = trim($line);
                foreach (array_filter([$validityPrefix, 'Este PIN no tiene fecha de vencimiento.']) as $prefix) {
                    if (str_starts_with($line, $prefix)) {
                        return trim(substr($line, strlen($prefix)));
                    }
                }

                return $line;
            }, $lines);
        }
        $lines = array_values(array_filter($lines, static fn (string $line) => trim($line) !== 'Seguimiento: '.$base.'/ingreso'
            && trim($line) !== 'Ingreso: '.$base.'/auth'
            && trim($line) !== $actionUrl
            && ! str_starts_with($line, 'Enlace privado: ')
            && ! ($pin && str_starts_with($line, 'Tu PIN es '.$pin.'.'))
        ));

        return [
            'subject' => $subject,
            'body' => $body,
            'lines' => $lines,
            'pin' => $pin,
            'pinValidity' => $pinValidity,
            'schoolName' => (string) (tenant()?->name ?: 'Colegio'),
            'actionUrl' => $actionUrl,
            'actionLabel' => $credentials ? 'Ingresar a mi cuenta' : ($pin ? 'Continuar inscripción' : 'Ver mi solicitud'),
        ];
    }
}
