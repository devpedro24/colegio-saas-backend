<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\StoredFile;
use App\Models\User;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class EnrollmentEvidenceFile
{
    public static function rules(): array
    {
        return ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png',
            'max:'.intdiv((int) config('storage.max_file_bytes'), 1024)];
    }

    /** Each transaction writes to its own folder; central metadata and disk are outside the tenant transaction. */
    public static function store(UploadedFile $upload, string $folder, User $actor, string $field): StoredFile
    {
        $name = $upload->getClientOriginalName();
        if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f]/u', $name)) {
            throw ValidationException::withMessages([$field => 'Revisa el nombre del archivo.']);
        }
        try {
            $file = app(StorageService::class)->store($upload, $folder, $actor->email);
        } catch (StorageException $error) {
            throw ValidationException::withMessages([$field => $error->getMessage()]);
        }
        DB::afterRollBack(function () use ($file) {
            Storage::disk($file->disk)->delete($file->path);
            $file->forceDelete();
        });

        return $file;
    }
}
