<?php

namespace App\Http\Controllers\Admin;

use App\Services\Common\LocaleService;
use App\Support\ApiResponse;

/** 各表单下拉用的通用选项（只读、按页面权限放行） */
class OptionController extends BaseAdminController
{
    public function __construct(private LocaleService $locale) {}

    /** 启用的币种（含基准标记和汇率），商品定价等表单用 */
    public function currencies()
    {
        return ApiResponse::ok($this->locale->currencies());
    }
}
