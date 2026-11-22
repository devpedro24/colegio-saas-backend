<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\SchoolSubscription;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SupportTicketController extends Controller
{
    public const STATES = ['abierto', 'en_proceso', 'esperando_colegio', 'resuelto', 'cerrado'];
    public const CATEGORIES = ['tecnico', 'acceso', 'academico', 'suscripcion', 'otro'];

    private function platform(Request $request): bool
    {
        $platform = ! tenancy()->initialized;
        abort_unless($platform ? $request->user()->role === 'superadmin'
            : ($request->user()->hasRole('rector') && ! $request->user()->esSuperadminPlataforma()), 403);
        abort_if($request->user()->must_change_password, 423, 'Cambia primero la contraseña temporal.');
        $this->rejectPrivateSelectors($request->all());
        return $platform;
    }

    private function rejectPrivateSelectors(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id'))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['request' => ['Usa los selectores públicos del recurso.']]);
            }
            if (is_array($value)) $this->rejectPrivateSelectors($value);
        }
    }

    private function scoped(Request $request): Builder
    {
        $platform = $this->platform($request);
        return SupportTicket::query()->when(! $platform,
            fn ($query) => $query->where('tenant_id', SchoolSubscription::owner(tenant())->getKey()));
    }

    private function ticket(Request $request, string $token): SupportTicket
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{24}$/D', $token), 404);
        return $this->scoped($request)->where('url_token', $token)->firstOrFail();
    }

    private function present(SupportTicket $ticket): array
    {
        return ['token' => $ticket->url_token, 'reference' => $ticket->reference,
            'school' => $ticket->school_name, 'origin' => $ticket->origin_name,
            'requester' => $ticket->requester_name, 'subject' => $ticket->subject,
            'category' => $ticket->category, 'status' => $ticket->status, 'priority' => $ticket->priority,
            'version' => $ticket->version, 'created_at' => $ticket->created_at->toIso8601String(),
            'updated_at' => $ticket->updated_at->toIso8601String()];
    }

    private function meta($page): array
    {
        return ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    private function audit(Request $request, SupportTicket $ticket, string $action): void
    {
        $target = tenancy()->initialized ? 'tenant' : 'platform';
        AuditLogger::$target($request->user(), $action, 'support.ticket', $ticket->url_token,
            null, ['reference' => $ticket->reference, 'status' => $ticket->status]);
    }

    public function index(Request $request)
    {
        $query = $this->scoped($request);
        $data = $request->validate(['status' => ['nullable', Rule::in(self::STATES)],
            'q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']]);
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['q']))).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(subject) LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("LOWER(reference) LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("LOWER(school_name) LIKE ? ESCAPE '!'", [$term]));
        }
        $page = $query->orderByRaw("CASE WHEN status IN ('resuelto', 'cerrado') THEN 1 ELSE 0 END")
            ->orderByDesc('priority')->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);
        return response()->json(['data' => $page->getCollection()->map(fn ($ticket) => $this->present($ticket)),
            'meta' => $this->meta($page)])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        abort_if($this->platform($request), 403);
        $data = $request->validate(['subject' => ['required', 'string', 'min:5', 'max:160'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'body' => ['required', 'string', 'min:10', 'max:8000'],
            'request_key' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/D']]);
        $owner = SchoolSubscription::owner(tenant());
        $result = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($request, $data, $owner) {
                // Serialize creation across all sites sharing this contract and read the current plan.
                $owner = Tenant::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                $existing = SupportTicket::where('tenant_id', $owner->id)->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    $first = SupportMessage::where('ticket_id', $existing->id)->oldest('id')->firstOrFail();
                    abort_unless($existing->subject === $data['subject'] && $existing->category === $data['category']
                        && $first->body === $data['body'] && $existing->requester_id === $request->user()->getKey(), 409);
                    return $existing;
                }
                $ticket = SupportTicket::create(['url_token' => Str::random(24), 'reference' => 'GG-'.Str::upper(Str::random(10)),
                    'tenant_id' => $owner->id, 'school_name' => $owner->name, 'origin_name' => tenant()->name,
                    'requester_id' => $request->user()->getKey(), 'requester_name' => $request->user()->name,
                    'request_key' => $data['request_key'], 'subject' => $data['subject'], 'category' => $data['category'],
                    'status' => 'abierto', 'priority' => in_array('soporte_prioritario', SchoolSubscription::effective($owner)?->features ?? [], true)]);
                $this->message($request, $ticket, $data['body'], $data['request_key']);
                $this->audit($request, $ticket, 'CREATE');
                return $ticket;
            });
        return response()->json(['data' => $this->present($result)], 201);
    }

    public function show(Request $request, string $token)
    {
        $ticket = $this->ticket($request, $token);
        $data = $request->validate(['messages_page' => ['nullable', 'integer', 'min:1']]);
        $query = SupportMessage::where('ticket_id', $ticket->id)->where('tenant_id', $ticket->tenant_id);
        $last = max(1, (int) ceil((clone $query)->count() / 30));
        $page = $query->oldest('id')->paginate(30, ['*'], 'messages_page', $data['messages_page'] ?? $last);
        return response()->json(['data' => $this->present($ticket),
            'messages' => $page->getCollection()->map(fn ($row) => ['side' => $row->author_side,
                'author' => $row->author_name, 'body' => $row->body, 'created_at' => $row->created_at->toIso8601String()]),
            'meta' => $this->meta($page)])->header('Cache-Control', 'private, no-store');
    }

    private function message(Request $request, SupportTicket $ticket, string $body, string $key): void
    {
        SupportMessage::create(['ticket_id' => $ticket->id, 'tenant_id' => $ticket->tenant_id,
            'author_id' => $request->user()->getKey(), 'author_name' => $request->user()->name,
            'author_side' => tenancy()->initialized ? 'colegio' : 'plataforma', 'body' => $body, 'request_key' => $key]);
    }

    public function reply(Request $request, string $token)
    {
        $ticket = $this->ticket($request, $token);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:8000'],
            'request_key' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/D']]);
        $ticket->getConnection()->transaction(function () use ($ticket, $request, $data) {
            $locked = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $existing = SupportMessage::where('ticket_id', $locked->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                abort_unless($existing->body === $data['body'] && $existing->author_id === $request->user()->getKey()
                    && $existing->author_side === (tenancy()->initialized ? 'colegio' : 'plataforma'), 409);
                return;
            }
            abort_if($locked->status === 'cerrado', 409, 'Reabre el ticket antes de enviar un mensaje.');
            $this->message($request, $locked, $data['body'], $data['request_key']);
            if (tenancy()->initialized && in_array($locked->status, ['esperando_colegio', 'resuelto'], true)) $locked->status = 'abierto';
            $locked->version++;
            $locked->save();
            $this->audit($request, $locked, 'UPDATE');
        });
        return response()->json(['ok' => true]);
    }

    public function state(Request $request, string $token)
    {
        $ticket = $this->ticket($request, $token);
        $platform = ! tenancy()->initialized;
        $data = $request->validate(['status' => ['required', Rule::in(self::STATES)], 'version' => ['required', 'integer', 'min:1']]);
        $ticket->getConnection()->transaction(function () use ($request, $ticket, $data, $platform) {
            $locked = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === $data['version'], 409, 'El ticket cambió. Actualiza la conversación e intenta nuevamente.');
            if (! $platform) abort_unless($data['status'] === 'cerrado'
                || ($data['status'] === 'abierto' && in_array($locked->status, ['resuelto', 'cerrado'], true)), 403);
            if ($locked->status !== $data['status']) {
                $locked->status = $data['status']; $locked->version++; $locked->save();
                $this->audit($request, $locked, 'UPDATE');
            }
        });
        return response()->json(['ok' => true]);
    }
}
