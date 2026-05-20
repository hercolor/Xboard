<?php

declare(strict_types=1);

use App\Http\Controllers\App\V1\BootstrapController;
use Illuminate\Support\Facades\Route;

Route::get('/bootstrap', BootstrapController::class)->name('app-api.bootstrap');
