<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;

/** Cliente ClamAV daemon mediante INSTREAM, sin invocar shell ni exponer rutas. */
final class ClamAvScanner implements FileScanner
{
    public function scan(string $disk, string $path): void
    {
        $endpoint = (string) config('storage.clamav.endpoint', 'tcp://127.0.0.1:3310');
        if (! str_starts_with($endpoint, 'tcp://') && ! str_starts_with($endpoint, 'unix://')) {
            throw new StorageException('La configuracion del antivirus no es valida.');
        }

        $source = Storage::disk($disk)->readStream($path);
        if (! is_resource($source)) {
            throw new StorageException('No se pudo leer el archivo para escanearlo.');
        }

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            $endpoint,
            $errno,
            $error,
            max(0.1, (float) config('storage.clamav.connect_timeout_seconds', 3)),
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($socket)) {
            fclose($source);
            throw new StorageException('El servicio antivirus no esta disponible.');
        }

        $readTimeout = max(1, (int) config('storage.clamav.read_timeout_seconds', 30));
        stream_set_timeout($socket, $readTimeout);
        $chunkBytes = min(1024 * 1024, max(1024, (int) config('storage.clamav.chunk_bytes', 65536)));

        try {
            $this->writeAll($socket, "zINSTREAM\0");
            while (! feof($source)) {
                $chunk = fread($source, $chunkBytes);
                if ($chunk === false) {
                    throw new StorageException('No se pudo leer el archivo para escanearlo.');
                }
                if ($chunk !== '') {
                    $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            $this->writeAll($socket, pack('N', 0));
            $response = $this->readResponse($socket);
        } finally {
            fclose($source);
            fclose($socket);
        }

        if (preg_match('/:\s+OK$/i', $response) === 1) {
            return;
        }

        if (preg_match('/:\s+(.+)\s+FOUND$/i', $response, $matches) === 1) {
            $signature = preg_replace('/[^a-zA-Z0-9._ -]/', '', $matches[1]) ?: 'desconocida';
            throw new StorageException('El antivirus detecto una amenaza ('.$signature.').');
        }

        throw new StorageException('El antivirus no pudo validar el archivo.');
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new StorageException('El servicio antivirus interrumpio el escaneo.');
            }
            $offset += $written;
        }
    }

    /** @param resource $socket */
    private function readResponse($socket): string
    {
        $response = '';
        while (strlen($response) < 8192) {
            $chunk = fread($socket, 1024);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $response .= $chunk;
                if (str_contains($response, "\0") || str_contains($response, "\n")) {
                    break;
                }
            } elseif (feof($socket) || (stream_get_meta_data($socket)['timed_out'] ?? false)) {
                break;
            }
        }

        return trim($response, "\0\r\n ");
    }
}
