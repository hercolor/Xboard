<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserBindPhone extends FormRequest
{
    public function rules()
    {
        return [
            'phone' => 'required|string|max:32|regex:/^\+?[0-9][0-9\s\-()]{5,30}$/',
            'phone_code' => 'nullable|string|min:4|max:8|required_without:code',
            'code' => 'nullable|string|min:4|max:8',
        ];
    }

    public function messages()
    {
        return [
            'phone.required' => __('Phone can not be empty'),
            'phone.regex' => __('Phone format is incorrect'),
            'phone_code.required_without' => __('Phone verification code cannot be empty'),
        ];
    }
}
