<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StatGetRanking extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:server_traffic_rank,user_consumption_rank,invite_rank',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => '排行类型不能为空',
            'type.in' => '排行类型格式不正确',
            'limit.integer' => '排行数量格式不正确',
            'limit.min' => '排行数量不能小于1',
            'limit.max' => '排行数量不能大于100',
        ];
    }
}
