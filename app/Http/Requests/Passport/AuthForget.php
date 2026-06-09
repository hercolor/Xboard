<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthForget extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'account' => 'nullable|string|max:128',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email:strict|required_without_all:account,phone',
            'password' => 'required|min:8',
            'email_code' => 'nullable|string|min:4|max:8|required_without_all:phone_code,code',
            'phone_code' => 'nullable|string|min:4|max:8|required_without_all:email_code,code',
            'code' => 'nullable|string|min:4|max:8'
        ];
    }

    public function messages()
    {
        return [
            'email.required_without_all' => __('Account can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits'),
            'email_code.required_without_all' => __('Verification code cannot be empty'),
            'phone_code.required_without_all' => __('Verification code cannot be empty')
        ];
    }
}
