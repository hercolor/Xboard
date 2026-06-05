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
            'email' => 'nullable|email:strict|required_without:account',
            'password' => 'required|min:8',
            'email_code' => 'required'
        ];
    }

    public function messages()
    {
        return [
            'account.required_without' => __('Account can not be empty'),
            'email.required_without' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits'),
            'email_code.required' => __('Email verification code cannot be empty')
        ];
    }
}
