<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AuthLogin extends FormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits'),
        ];
    }
}
