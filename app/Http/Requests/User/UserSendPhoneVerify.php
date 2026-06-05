<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserSendPhoneVerify extends FormRequest
{
    public function rules()
    {
        return [
            'phone' => 'required|string|max:32|regex:/^\+?[0-9][0-9\s\-()]{5,30}$/',
        ];
    }

    public function messages()
    {
        return [
            'phone.required' => __('Phone can not be empty'),
            'phone.regex' => __('Phone format is incorrect'),
        ];
    }
}
