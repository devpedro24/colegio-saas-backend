<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Private recovery storage. These writes never mutate academic records or broadcast them. */
final class WorkDraftController extends Controller
{
    private const MAX_BYTES = 8_000_000;

    private const MAX_DRAFTS = 100;

    private function vault(Request $request): object
    {
        $user = $request->user()->getKey();
        DB::table('work_draft_vaults')->insertOrIgnore([
            'user_id' => $user,
            'scope' => rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='),
            'encrypted_key' => Crypt::encryptString(base64_encode(random_bytes(32))),
        ]);

        return DB::table('work_draft_vaults')->where('user_id', $user)->first();
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    private function present(object $row): array
    {
        return ['token' => $row->token, 'version' => (int) $row->version,
            'iv' => $row->iv, 'ciphertext' => $row->ciphertext,
            'edited_at' => Carbon::parse($row->edited_at)->toISOString(),
            'expires_at' => Carbon::parse($row->expires_at)->toISOString(),
            'discarded' => (bool) $row->discarded];
    }

    public function index(Request $request): JsonResponse
    {
        $vault = $this->vault($request);
        $rows = DB::table('work_drafts')->where('user_id', $request->user()->getKey())
            ->where('expires_at', '>', now())->orderByDesc('edited_at')->get();

        return $this->response(['scope' => $vault->scope,
            'key' => Crypt::decryptString($vault->encrypted_key),
            'drafts' => $rows->map(fn ($row) => $this->present($row))->all(),
            'server_time' => now()->toISOString()]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'iv' => ['required', 'string', 'size:16', 'regex:/\A[A-Za-z0-9+\/=]+\z/'],
            'ciphertext' => ['required', 'string', 'max:700000', 'regex:/\A[A-Za-z0-9+\/=]+\z/'],
            'edited_at' => ['required', 'date'],
            'files' => ['sometimes', 'array', 'max:30'],
            'files.*' => ['string', 'regex:/\A[A-Za-z0-9_-]{24}\z/'],
        ]);
        abort_unless(strlen(base64_decode($input['iv'], true) ?: '') === 12
            && strlen(base64_decode($input['ciphertext'], true) ?: '') >= 16, 422);
        // Offline copies keep their original expiry. Reading/syncing does not extend retention.
        $edited = Carbon::parse($input['edited_at'])->utc();
        abort_if($edited->isAfter(now()->addMinutes(2)), 422);
        $edited = $edited->min(now());
        abort_if($edited->copy()->addDay()->lte(now()), 410);
        $vault = $this->vault($request);

        return DB::transaction(function () use ($request, $token, $input, $edited, $vault) {
            // Serialize quota and revision checks for this owner, including concurrent inserts.
            DB::table('work_draft_vaults')->where('scope', $vault->scope)->lockForUpdate()->first();
            $user = $request->user()->getKey();
            $existing = DB::table('work_drafts')->where('token', $token)->first();
            abort_if($existing && (string) $existing->user_id !== (string) $user, 404);
            abort_if($existing && ($existing->discarded || Carbon::parse($existing->expires_at)->lte(now())), 410);
            // Retry after a lost acknowledgement: no new revision and no TTL extension.
            if ($existing && (int) $existing->version === $input['version'] + 1
                && hash_equals((string) $existing->iv, $input['iv'])
                && hash_equals((string) $existing->ciphertext, $input['ciphertext'])) {
                return $this->response($this->present($existing));
            }
            abort_if((int) ($existing->version ?? 0) !== $input['version'], 409);
            $live = DB::table('work_drafts')->where('user_id', $user)->where('discarded', false)->where('expires_at', '>', now());
            abort_if(! $existing && (clone $live)->count() >= self::MAX_DRAFTS, 413);
            $bytes = (clone $live)->get(['ciphertext'])->sum(fn ($row) => strlen($row->ciphertext ?? ''));
            abort_if($bytes - strlen($existing->ciphertext ?? '') + strlen($input['ciphertext']) > self::MAX_BYTES, 413);
            abort_if($existing && $edited->lt(Carbon::parse($existing->edited_at)), 409);
            DB::table('work_drafts')->updateOrInsert(['token' => $token], [
                'user_id' => $user, 'version' => $input['version'] + 1, 'iv' => $input['iv'],
                'ciphertext' => $input['ciphertext'], 'edited_at' => $edited,
                'expires_at' => $edited->copy()->addDay(), 'discarded' => false,
            ]);
            if (! empty($input['files']) && tenancy()->initialized) {
                DB::table('work_draft_files')->where('user_id', $user)->whereIn('token', $input['files'])
                    ->where('expires_at', '>', now())->update(['expires_at' => $edited->copy()->addDay()]);
            }

            return $this->response($this->present(DB::table('work_drafts')->where('token', $token)->first()));
        });
    }

    public function discard(Request $request, string $token): JsonResponse
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:0']]);
        $vault = $this->vault($request);

        return DB::transaction(function () use ($request, $token, $input, $vault) {
            DB::table('work_draft_vaults')->where('scope', $vault->scope)->lockForUpdate()->first();
            $row = DB::table('work_drafts')->where('token', $token)->first();
            abort_if($row && (string) $row->user_id !== (string) $request->user()->getKey(), 404);
            if ($row && $row->discarded) {
                return $this->response($this->present($row));
            }
            abort_if((int) ($row->version ?? 0) !== $input['version'], 409);
            abort_if(! $row && DB::table('work_drafts')->where('user_id', $request->user()->getKey())
                ->where('discarded', true)->where('expires_at', '>', now())->count() >= 1000, 429);
            // Tombstone blocks stale/offline copies from resurrecting a saved or discarded draft.
            DB::table('work_drafts')->updateOrInsert(['token' => $token], [
                'user_id' => $request->user()->getKey(), 'version' => $input['version'] + 1,
                'iv' => null, 'ciphertext' => null, 'edited_at' => now(),
                'expires_at' => now()->addDay(), 'discarded' => true,
            ]);

            return $this->response($this->present(DB::table('work_drafts')->where('token', $token)->first()));
        });
    }
}
