<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContactNumbers;
use Illuminate\Http\JsonResponse;

final class ContactOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['countries' => ContactNumbers::countries()]);
    }
}
