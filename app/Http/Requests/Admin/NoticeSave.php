<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'id' => 'nullable|integer',
            'title' => 'required|string',
            'content' => 'required|string',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array',
            'show' => 'nullable|boolean',
            'popup' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom validation messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'id.integer' => '公告ID格式不正确',
            'title.required' => '标题不能为空',
            'content.required' => '内容不能为空',
            'img_url.url' => '图片URL格式不正确',
            'tags.array' => '标签格式不正确',
            'show.boolean' => '展示状态格式不正确',
            'popup.boolean' => '弹窗状态格式不正确',
        ];
    }
}
