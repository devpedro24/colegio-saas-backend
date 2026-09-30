<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Facades\Storage;

/** Streams uploads to a private clamd daemon using the documented INSTREAM protocol. */
final class ClamAvScanner implements FileScanner
{
    public function scan(string $disk, string $path): void
    {
        $host = (string) config('storage.clamav_host', '127.0.0.1');
        $port = (int) config('storage.clamav_port', 3310);
        $timeout = max(1, (int) config('storage.clamav_timeout_seconds', 10));
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new StorageException('No se pudo analizar el archivo.');
        }

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errorNumber, $errorMessage, $timeout);
        if (! is_resource($socket)) {
            fclose($stream);
            throw new StorageException('El análisis de seguridad de archivos no está disponible.');
        }

        stream_set_timeout($socket, $timeout);
        try {
            $this->writeAll($socket, "zINSTREAM\0");
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new StorageException('No se pudo analizar el archivo.');
                }
                if ($chunk !== '') {
                    $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
                }
            }
            $this->writeAll($socket, pack('N', 0));

            $reply = '';
            while (strlen($reply) < 4096) {
                $byte = fread($socket, 1);
                if ($byte === false || $byte === '') {
                    break;
                }
                if ($byte === "\0") {
                    break;
                }
                $reply .= $byte;
            }
            if (str_ends_with($reply, ' OK')) {
                return;
            }
            if (str_contains($reply, ' FOUND')) {
                throw new StorageException('El archivo no superó el análisis de seguridad.');
            }
            throw new StorageException('El análisis de seguridad de archivos no se completó.');
        } finally {
            fclose($socket);
            fclose($stream);
        }
    }

    /** fwrite may send fewer bytes than requested; send the complete record. */
    private function writeAll($socket, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new StorageException('El análisis de seguridad de archivos no se completó.');
            }
            $data = substr($data, $written);
        }
    }
}
