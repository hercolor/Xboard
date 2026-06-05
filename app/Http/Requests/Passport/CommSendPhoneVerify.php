<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class CommSendPhoneVerify extends FormRequest
{
    public function rules()
    {
        return [
            'account' => 'nullable|string|max:128',
            'phone' => 'nullable|string|max:32|required_without:account',
        ];
    }

    public function messages()
    {
        return [
            'account.required_without' => __('Account can not be empty'),
            'phone.required_without' => __('Phone can not be empty'),
        ];
    }
}
