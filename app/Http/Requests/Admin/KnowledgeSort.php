<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSort extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize legacy payload shape.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('ids') && $this->has('knowledge_ids')) {
            $this->merge([
                'ids' => $this->input('knowledge_ids'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'ids.required' => '知识ID不能为空',
            'ids.array' => '知识ID格式有误',
            'ids.*.integer' => '知识ID必须是整数',
        ];
    }
}
