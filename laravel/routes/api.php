<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Endpoints called by Python microservices. Group routes by source service so each one's
// concerns stay isolated and future services (e.g. syllabi) can add their own without collisions.
Route::prefix('microservices')->group(function () {
    Route::prefix('lo-mapping')->group(function () {
        Route::post('/ai-suggestions/store', [\App\Http\Controllers\CourseProgramController::class, 'storeAiSuggestions']);
    });
});