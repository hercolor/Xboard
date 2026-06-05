<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class CommSendEmailVerify extends FormRequest
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
            'email' => 'nullable|email:strict|required_without:account'
        ];
    }

    public function messages()
    {
        return [
            'account.required_without' => __('Account can not be empty'),
            'email.required_without' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect')
        ];
    }
}
