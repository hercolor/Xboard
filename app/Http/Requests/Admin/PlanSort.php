<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PlanSort extends FormRequest
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
        if (!$this->has('ids') && $this->has('plan_ids')) {
            $this->merge([
                'ids' => $this->input('plan_ids'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'ids' => 'required|array',
            'ids.*' => 'integer'
        ];
    }

    public function messages()
    {
        return [
            'ids.required' => '订阅计划ID不能为空',
            'ids.array' => '订阅计划ID格式有误',
            'ids.*.integer' => '订阅计划ID必须是整数',
        ];
    }
}
