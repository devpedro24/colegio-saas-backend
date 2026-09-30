<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Account\AccountPresenter;
use Illuminate\Http\JsonResponse;

final class InstitutionContextController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => AccountPresenter::institution()]);
    }
}
