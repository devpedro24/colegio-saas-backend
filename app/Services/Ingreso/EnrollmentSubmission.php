<?php

declare(strict_types=1);

namespace App\Services\Ingreso;

use App\Models\Ingreso\Solicitud;
use App\Models\StoredFile;
use App\Support\Storage\StorageException;
use App\Support\Storage\StorageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EnrollmentSubmission
{
    public function __construct(private EnrollmentIntake $intake, private IdentityDocumentReader $reader, private StorageService $storage) {}

    public static function geography(): array
    {
        return json_decode(file_get_contents(resource_path('data/colombia-divipola.json')), true, flags: JSON_THROW_ON_ERROR)['departamentos'];
    }

    public static function limits(): array
    {
        $bytes = static function (string $limit): int {
            $limit = trim($limit);
            $unit = strtolower(substr($limit, -1));
            return (int) ((float) $limit * match ($unit) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1,
            });
        };
        $post = $bytes((string) ini_get('post_max_size'));
        $upload = $bytes((string) ini_get('upload_max_filesize'));
        $policy = (int) config('enrollment.max_file_bytes');
        $batchPolicy = (int) config('enrollment.max_submission_bytes');

        return ['max_file_bytes' => $upload > 0 ? min($policy, $upload) : $policy,
            'max_submission_bytes' => $post > 0 ? min($batchPolicy, max(1, $post - 1048576)) : $batchPolicy,
            'max_archivos' => min(30, (int) ini_get('max_file_uploads'))];
    }

    /** Called under the application's row lock; caller compensates central storage on rollback. */
    public function submit(Request $r, Solicitud $s, array &$created): void
    {
        if (is_string($r->input('datos'))) {
            $decoded = json_decode($r->input('datos'), true);
            $r->merge(['datos' => is_array($decoded) ? $decoded : null]);
        }
        // JSON inside multipart is not processed by Laravel's TrimStrings middleware.
        $clean = function ($value) use (&$clean) {
            return is_array($value) ? array_map($clean, $value) : (is_string($value) ? trim($value) : $value);
        };
        $r->merge(['datos' => $clean($r->input('datos'))]);
        $rules = [
            'enviar' => 'accepted', 'consentimiento' => 'accepted',
            'privacidad_version' => 'required|string|size:64',
            'datos' => 'required|array:primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,nacimiento,tipo_documento,numero_documento,telefono,direccion,pais,departamento,municipio,adicionales',
            'datos.primer_nombre' => 'required|string|max:80', 'datos.segundo_nombre' => 'nullable|string|max:80',
            'datos.primer_apellido' => 'required|string|max:80', 'datos.segundo_apellido' => 'nullable|string|max:80',
            'datos.nacimiento' => 'required|date_format:Y-m-d|before:today|after:1900-01-01',
            'datos.tipo_documento' => ['required', Rule::in(EnrollmentIntake::DOCUMENT_TYPES)],
            'datos.numero_documento' => 'required|string|regex:/^[a-zA-Z0-9-]{3,40}$/',
            'datos.telefono' => ['required', 'string', 'regex:/^\+[0-9]{7,15}$/'],
            'datos.direccion' => 'required|string|max:200', 'datos.pais' => 'required|string|max:80',
            'datos.departamento' => 'required|string|max:100', 'datos.municipio' => 'required|string|max:100',
            'datos.adicionales' => 'present|array',
            'documentos' => 'sometimes|array|max:30', 'retirar' => 'sometimes|array|max:30', 'retirar.*' => 'string|distinct|max:50',
        ];
        foreach ($s->campana->configuracion['campos'] as $field) {
            $phone = preg_match('/telefono|celular|movil/', Str::lower(Str::ascii($field['key'].' '.$field['nombre'])));
            $rules['datos.adicionales.'.$field['key']] = [$field['obligatorio'] ? 'required' : 'nullable',
                ...($field['tipo'] === 'fecha' ? ['date_format:Y-m-d'] : ($phone ? ['string', 'regex:/^\+[0-9]{7,15}$/'] : ['string', 'max:1000']))];
        }
        $requirements = collect($this->intake->requirements($s))->keyBy('key');
        foreach ($requirements as $key => $requirement) {
            $formats = $requirement['formatos'];
            if (in_array('jpg', $formats, true)) {
                $formats[] = 'jpeg';
            }
            $rules['documentos.'.$key] = ['sometimes', 'file', 'mimes:'.implode(',', $formats), 'extensions:'.implode(',', $formats), 'max:'.(int) ceil(self::limits()['max_file_bytes'] / 1024)];
        }
        $d = $r->validate($rules);
        // Keep one policy throughout validation, file processing and the final commit.
        DB::table('ingreso_configuracion')->sharedLock()->find(1);
        $settings = app(EnrollmentSettings::class);
        $notice = $settings->configuration($s->campana)['privacidad'];
        if (! hash_equals(hash('sha256', $notice), $d['privacidad_version'])) {
            $this->error('privacidad_version', 'intake.privacyChanged');
        }
        $data = $d['datos'];
        if (array_diff(array_keys($data['adicionales']), array_column($s->campana->configuracion['campos'], 'key'))) {
            $this->error('datos.adicionales', 'intake.unknownField');
        }
        foreach ([$data['telefono'], ...array_values($data['adicionales'])] as $phone) {
            if (is_string($phone) && str_starts_with($phone, '+57') && ! preg_match('/^\+573[0-9]{9}$/', $phone)) {
                $this->error('datos.telefono', 'intake.invalidPhone');
            }
        }
        foreach ($s->campana->configuracion['campos'] as $field) {
            if ($field['obligatorio'] && preg_match('/parentesco|relacion|vinculo/', Str::lower(Str::ascii($field['key'].' '.$field['nombre'])))
                && Str::lower($data['adicionales'][$field['key']] ?? '') === 'otro') {
                $this->error('datos.adicionales.'.$field['key'], 'intake.specifyRelationship');
            }
        }
        if ($data['pais'] === 'CO') {
            $department = collect(self::geography())->firstWhere('codigo', $data['departamento']);
            if (! $department || ! collect($department['municipios'])->contains('codigo', $data['municipio'])) {
                $this->error('datos.municipio', 'intake.invalidMunicipality');
            }
        }
        $birth = CarbonImmutable::parse($data['nacimiento'], tenant()->zonaHorariaInstitucional());
        $today = CarbonImmutable::now(tenant()->zonaHorariaInstitucional())->startOfDay();
        if ($birth->year >= $today->year) {
            $this->error('datos.nacimiento', 'intake.invalidBirth');
        }
        $ageValidation = $settings->ageValidation($s, $data);
        $renewal = app(EnrollmentRenewal::class)->find($s, $data['tipo_documento'], $data['numero_documento']);
        if ($s->estudiante_id && $s->estudiante_id !== $renewal?->id) {
            $this->error('datos.numero_documento', 'intake.identityNumberMismatch');
        }
        $files = $r->file('documentos', []);
        foreach ($files as $key => $file) {
            if ($file->getSize() > self::limits()['max_file_bytes']) {
                $this->error('documentos.'.$key, 'intake.fileTooLarge');
            }
        }
        $remove = $d['retirar'] ?? [];
        if (array_diff([...array_keys($files), ...$remove], $requirements->keys()->all())) {
            $this->error('documentos', 'intake.unknownField');
        }
        if (array_sum(array_map(fn ($f) => $f->getSize(), $files)) > self::limits()['max_submission_bytes']) {
            $this->error('documentos', 'intake.batchTooLarge');
        }
        $latest = $s->documentos()->orderByDesc('version')->get()->unique('requisito')->keyBy('requisito');
        foreach ($requirements as $key => $req) {
            $doc = $latest->get($key);
            if (($files[$key] ?? null) || in_array($key, $remove, true)) {
                if ($doc?->estado === 'aprobado') {
                    $this->error('documentos.'.$key, 'intake.approvedImmutable');
                }
            }
            $available = isset($files[$key]) || ($doc && ! in_array($doc->estado, ['retirado', 'rechazado'], true) && ! in_array($key, $remove, true));
            if (($req['obligatorio'] || ($doc?->estado === 'rechazado' && ! in_array($key, $remove, true))) && ! $available) {
                throw ValidationException::withMessages(['documentos.'.$key => __('Falta el documento: ').$req['nombre']]);
            }
            if (isset($files[$key]) && ($doc?->version ?? 0) >= 20) {
                $this->error('documentos.'.$key, 'intake.versionLimit');
            }
        }
        // Explicit configuration preferred; legacy identity labels work until staff assigns the role.
        $identityCandidates = $requirements->filter(fn ($req) => (bool) preg_match('/identidad|identifica|identity/', Str::lower(Str::ascii($req['key'].' '.$req['nombre']))));
        $identityKey = $s->campana->configuracion['documento_identidad'] ?? ($identityCandidates->count() === 1 ? $identityCandidates->keys()->first() : null);
        if (! $identityKey || ! $requirements->has($identityKey)) {
            $this->error('documentos', 'intake.identityNotConfigured');
        }
        if (in_array($identityKey, $remove, true) && ! isset($files[$identityKey])) {
            $this->error('documentos.'.$identityKey, 'intake.identityRequired');
        }

        // Unique batch directory guarantees cleanup cannot remove a reused historical file.
        $batch = 'matriculas/Envio_'.Str::uuid();
        $stored = [];
        foreach ($files as $key => $file) {
            try {
                $stored[$key] = $this->storage->store($file, $batch.'/'.$key, $s->email, maxBytes: (int) config('enrollment.max_file_bytes'));
            } catch (StorageException $e) {
                throw ValidationException::withMessages(['documentos.'.$key => $e->getMessage()]);
            }
            $created[] = $stored[$key];
        }
        $identity = $stored[$identityKey] ?? null;
        if (! $identity) {
            $doc = $latest->get($identityKey);
            if (! $doc || in_array($doc->estado, ['retirado', 'rechazado'], true)) {
                $this->error('documentos.'.$identityKey, 'intake.identityRequired');
            }
            $identity = StoredFile::where('tenant_id', tenant()->getTenantKey())->findOrFail($doc->archivo_id);
        }
        $this->reader->validate($this->reader->read(Storage::disk($identity->disk)->path($identity->path), $identity->mime), $data);
        foreach ($remove as $key) {
            if ($latest->has($key)) {
                $latest[$key]->update(['estado' => 'retirado']);
            }
        }
        foreach ($stored as $key => $file) {
            $s->documentos()->create(['requisito' => $key, 'version' => ($latest->get($key)?->version ?? 0) + 1,
                'archivo_id' => $file->id, 'nombre' => $file->original_name, 'estado' => 'pendiente']);
        }
        $data['numero_documento'] = Str::upper($data['numero_documento']);
        $s->fill(['datos' => $data, 'estudiante_id' => $renewal?->id, 'estado' => 'enviada', 'enviada_en' => now(), 'consentimiento_en' => now(),
            'validacion_edad' => $ageValidation,
            'consentimiento_privacidad' => ['texto' => $notice, 'version' => $d['privacidad_version']]])->save();
        DB::table('ingreso_envios')->insert(['solicitud_id' => $s->id, 'envio_token' => $r->input('envio_token'),
            'validacion_edad' => json_encode($ageValidation, JSON_THROW_ON_ERROR),
            'consentimiento_privacidad' => json_encode($s->consentimiento_privacidad, JSON_THROW_ON_ERROR), 'enviada_en' => now()]);
        $this->intake->event($s, 'enviada');
        $this->intake->notify($s, 'envio:'.$r->input('envio_token'), 'Solicitud de matrícula recibida', 'Recibimos tu solicitud. Podrás consultar el estado de cada documento en el portal.');
    }

    private function error(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __($key)]);
    }
}
