<?php

declare(strict_types=1);

use App\Http\Controllers\App\V1\BootstrapController;
use App\Http\Controllers\App\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::get('/bootstrap', BootstrapController::class)->name('app-api.bootstrap');
Route::get('/session', SessionController::class)
    ->middleware('user')
    ->name('app-api.session');
