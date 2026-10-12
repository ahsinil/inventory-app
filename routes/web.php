<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::view('/api/docs', 'api-docs');

Route::get('/live', [HealthController::class, 'live']);
Route::get('/ready', [HealthController::class, 'ready']);
Route::get('/health', [HealthController::class, 'health']);

Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
