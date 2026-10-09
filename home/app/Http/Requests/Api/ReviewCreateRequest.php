<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReviewCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_item_id' => ['required', 'integer', 'min:1'],
            'rating'        => ['required', 'integer', 'min:1', 'max:5'],
            'content'       => ['nullable', 'string', 'max:1000'],
            'images'        => ['nullable', 'array', 'max:6'],
            'images.*'      => ['string', 'max:255'],
            'is_anonymous'  => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['order_item_id' => '评价商品', 'rating' => '评分', 'content' => '评价内容', 'images' => '图片', 'is_anonymous' => '匿名'];
    }
}
