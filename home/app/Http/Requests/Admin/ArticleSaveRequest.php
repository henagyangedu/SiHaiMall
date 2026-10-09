<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 文章 / 公告 / 帮助 新增与编辑共用。默认语言的标题和正文必填，在 ArticleService 校验 */
class ArticleSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id'  => ['nullable', 'integer', 'min:0'],
            'title'        => ['required', 'array'],
            'title.*'      => ['nullable', 'string', 'max:255'],
            'summary'      => ['nullable', 'array'],
            'summary.*'    => ['nullable', 'string', 'max:500'],
            'content'      => ['required', 'array'],
            'content.*'    => ['nullable', 'string', 'max:500000'],
            'seo_title'    => ['nullable', 'array'],
            'seo_title.*'  => ['nullable', 'string', 'max:255'],
            'seo_desc'     => ['nullable', 'array'],
            'seo_desc.*'   => ['nullable', 'string', 'max:500'],
            'cover'        => ['nullable', 'string', 'max:255'],
            'author'       => ['nullable', 'string', 'max:60'],
            'is_top'       => ['nullable', 'in:0,1'],
            'sort'         => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status'       => ['nullable', 'in:0,1'],
            'published_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ];
    }

    public function attributes(): array
    {
        return [
            'category_id' => '分类', 'title' => '标题', 'title.*' => '标题', 'summary.*' => '摘要', 'content' => '正文', 'content.*' => '正文',
            'seo_title.*' => 'SEO 标题', 'seo_desc.*' => 'SEO 描述', 'cover' => '封面', 'author' => '作者', 'sort' => '排序', 'published_at' => '发布时间',
        ];
    }
}
