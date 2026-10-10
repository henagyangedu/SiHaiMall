<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReviewAppendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_item_id' => ['required', 'integer', 'min:1'],
            'content'       => ['required', 'string', 'max:500'],
            'images'        => ['nullable', 'array', 'max:6'],
            'images.*'      => ['string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['order_item_id' => '评价商品', 'content' => '追评内容', 'images' => '图片'];
    }
}
