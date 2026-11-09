<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class NotificationRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(tenancy()->initialized, 404);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $records = DB::table('user_notification_records')->where('user_id', $request->user()->getKey())
            ->orderByDesc('created_at')->orderByDesc('url_token')->paginate(20);

        return response()->json([
            'data' => $records->getCollection()->map(fn ($row) => [
                'token' => $row->url_token, 'variant' => $row->variant, 'event' => $row->event,
                'subject' => $row->subject, 'message' => $row->message,
                'created_at' => Carbon::parse($row->created_at)->toISOString(),
            ]),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'total' => $records->total()],
        ])->header('Cache-Control', 'private, no-store');
    }
}
