<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuthLogin;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected LoginService $loginService;

    public function __construct(LoginService $loginService)
    {
        $this->loginService = $loginService;
    }

    public function login(AuthLogin $request)
    {
        [$success, $result] = $this->loginService->login(
            $request->input('email'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        /** @var User $user */
        $user = $result;
        if (!$user->is_admin) {
            return $this->fail([403000, 'Unauthorized']);
        }

        $authService = new AuthService($user);
        return $this->success($authService->generateAuthData());
    }

    public function me(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success([
            'id' => $user->id,
            'email' => $user->email,
            'is_admin' => (bool) $user->is_admin,
            'is_staff' => (bool) $user->is_staff,
            'avatar_url' => 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon',
            'last_login_at' => $user->last_login_at,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $currentAccessToken = $user?->currentAccessToken();

        if ($currentAccessToken) {
            $currentAccessToken->delete();
        }

        return $this->success(true);
    }
}
