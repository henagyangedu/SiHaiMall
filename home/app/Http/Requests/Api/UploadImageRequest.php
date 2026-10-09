<?php

namespace App\Http\Requests\Api;

use App\Services\Common\UploadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file'  => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB
            'scene' => ['required', Rule::in(UploadService::MEMBER_SCENES)],
        ];
    }

    public function attributes(): array
    {
        return ['file' => '图片', 'scene' => '图片用途'];
    }
}
