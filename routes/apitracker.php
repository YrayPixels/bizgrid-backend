<?php

use App\Http\Controllers\UsageTrackerController;
use Illuminate\Support\Facades\Route;


/*
* This is the sdk manager routes
*
*/

Route::prefix('track')->group(function () {

    //Button Clicks //done
    Route::post('/button-clicks', [UsageTrackerController::class, 'button_clicks']);

    //Transaction History
    Route::post('/transaction-history', [UsageTrackerController::class, 'transaction_history']);

    //Tool Calls
    Route::post('/tool-calls', [UsageTrackerController::class, 'tool_calls']);

    //App Open Count
    Route::post('/app-open-count', [UsageTrackerController::class, 'app_open_count']);

    //Page Open Count
    Route::post('/page-open-count', [UsageTrackerController::class, 'page_open_count']);

    Route::post('/user-activity', [UsageTrackerController::class, 'user_activity']);

    //Token Usage
    Route::post('/token-usage', [UsageTrackerController::class, 'token_usage']);



    // These routes are for getting data and analytics so they should be returned in analytics


    Route::get('get-tracking-data', [UsageTrackerController::class, 'get_tracking_data']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('engagement-analytics', [UsageTrackerController::class, 'get_engagement_analytics']);
    });




    //Transactions Route



});




