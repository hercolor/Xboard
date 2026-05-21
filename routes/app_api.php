<?php

declare(strict_types=1);

use App\Http\Controllers\App\V1\BootstrapController;
use App\Http\Controllers\App\V1\DashboardController;
use App\Http\Controllers\App\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/bootstrap', BootstrapController::class)
    ->middleware('throttle:app-read')
    ->name('app-api.bootstrap');
Route::get('/session', SessionController::class)
    ->middleware(['user', 'throttle:app-read'])
    ->name('app-api.session');
Route::get('/dashboard', DashboardController::class)
    ->middleware(['user', 'throttle:app-read'])
    ->name('app-api.dashboard');
