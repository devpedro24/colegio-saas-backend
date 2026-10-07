<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Controller;
use App\Services\AulaOfficeViewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Status-only callback for read-only documents. It never accepts edited bytes. */
final class AulaOfficeCallbackController extends Controller
{
    public function __invoke(Request $request, AulaOfficeViewer $viewer): JsonResponse
    {
        $token = preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization'));
        abort_unless($viewer->validCallbackToken($token), 403);
        // A view can connect (1) or close unchanged (4); no edit/save is allowed.
        $status = (int) $request->input('status');
        return response()->json(['error' => in_array($status, [1, 4], true) ? 0 : 1]);
    }
}
