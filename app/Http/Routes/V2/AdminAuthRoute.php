<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Admin\AuthController;
use Illuminate\Contracts\Routing\Registrar;

class AdminAuthRoute
{
    public function map(Registrar $router)
    {
        $prefix = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

        $router->group([
            'prefix' => $prefix . '/auth',
        ], function ($router) {
            $router->post('/login', [AuthController::class, 'login']);

            $router->group([
                'middleware' => ['user', 'admin'],
            ], function ($router) {
                $router->get('/me', [AuthController::class, 'me']);
                $router->post('/logout', [AuthController::class, 'logout']);
            });
        });
    }
}
