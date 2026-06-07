<?php

use App\Http\Controllers\AppTransactionsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v2')->group(function () {
    Route::post('/app-transactions', [AppTransactionsController::class, 'store']);
});

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/app-transactions/metrics', [AppTransactionsController::class, 'metrics']);
});
