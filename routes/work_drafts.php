<?php

use App\Http\Controllers\Api\WorkDraftController;
use Illuminate\Support\Facades\Route;

Route::prefix('work-drafts')->middleware('throttle:120,1')->controller(WorkDraftController::class)->group(function () {
    Route::get('/', 'index');
    Route::put('/{token}', 'store')->where('token', '[A-Za-z0-9_-]{24}');
    Route::post('/{token}/discard', 'discard')->where('token', '[A-Za-z0-9_-]{24}');
});
