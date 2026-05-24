<?php

declare(strict_types=1);

use App\Http\Controllers\App\V1\BootstrapController;
use App\Http\Controllers\App\V1\DashboardController;
use App\Http\Controllers\App\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/bootstrap', BootstrapController::class)
    ->middleware(['throttle:app-read', 'api.request_size:app', 'api.cache_headers:bootstrap'])
    ->name('app-api.bootstrap');
Route::get('/session', SessionController::class)
    ->middleware(['user', 'throttle:app-read', 'api.request_size:app'])
    ->name('app-api.session');
Route::get('/dashboard', DashboardController::class)
    ->middleware(['user', 'throttle:app-read', 'api.request_size:app'])
    ->name('app-api.dashboard');
