<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file'  => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,ico', 'max:5120'], // 5MB
            'scene' => ['nullable', 'in:goods,avatar,banner,editor,setting,other'],
        ];
    }

    public function attributes(): array
    {
        return ['file' => '图片', 'scene' => '用途'];
    }
}
