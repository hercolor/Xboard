<?php

use App\Services\UpdateService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
Route::get('/', function () {
    abort(404);
});

Route::get('/rules/sing-box/{file}', function (string $file) {
    if (!in_array($file, ['geosite-cn.srs', 'geoip-cn.srs'], true)) {
        abort(404);
    }

    $path = storage_path('app/rules/sing-box/' . $file);
    if (!is_file($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/octet-stream',
        'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('file', 'geosite-cn\.srs|geoip-cn\.srs')->name('rules.sing-box');

Route::get('/appcast.xml', [\App\Http\Controllers\App\V1\ClientVersionController::class, 'appcast'])
    ->name('client-version.appcast');
Route::get('/app/releases', [\App\Http\Controllers\App\V1\ClientVersionController::class, 'releases'])
    ->name('client-version.releases');
Route::get('/app/latest', [\App\Http\Controllers\App\V1\ClientVersionController::class, 'latest'])
    ->name('client-version.latest');

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware(['client', 'throttle:subscription'])
    ->name('client.subscribe');
