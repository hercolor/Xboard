<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MailTemplateSave extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '模板名称不能为空',
            'name.string' => '模板名称格式有误',
            'subject.required' => '邮件主题不能为空',
            'subject.string' => '邮件主题格式有误',
            'subject.max' => '邮件主题长度不能超过255个字符',
            'content.required' => '邮件内容不能为空',
            'content.string' => '邮件内容格式有误',
        ];
    }
}
