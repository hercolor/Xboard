<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserSetInvite extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer',
            'invite_user_email' => 'nullable|email:strict',
            'invite_user_id' => 'nullable|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => '用户ID不能为空',
            'id.integer' => '用户ID格式不正确',
            'invite_user_email.email' => '邀请人邮箱格式不正确',
            'invite_user_id.integer' => '邀请人ID格式不正确',
        ];
    }
}
