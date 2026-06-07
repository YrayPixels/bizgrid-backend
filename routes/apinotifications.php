<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Push Notification API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for push notifications.
| These routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group.
|
*/

Route::prefix('notifications')->group(function () {
    // Register a push notification token
    Route::post('/register-token', [NotificationController::class, 'registerToken']);
    
    // Send a push notification
    Route::post('/send', [NotificationController::class, 'sendNotification']);
    
    // Get user tokens
    Route::get('/tokens', [NotificationController::class, 'getUserTokens']);
    
    // Deactivate a token
    Route::post('/deactivate-token', [NotificationController::class, 'deactivateToken']);
    
    // Send a test notification
    Route::post('/test', [NotificationController::class, 'sendTestNotification']);
}); 