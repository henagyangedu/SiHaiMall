<?php

namespace App\Http\Controllers\Api;

use App\Models\Article;
use App\Services\Api\ContentService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** C 端内容：文章 / 公告 / 帮助中心 / 广告位，全部无需登录 */
class ContentController extends ApiController
{
    public function __construct(private ContentService $service) {}

    /** type = article（默认）/ notice / help */
    public function articles(Request $request)
    {
        return ApiResponse::ok($this->service->articles($this->type($request), $request->only(['category_id', 'page', 'size']), $this->lang($request)));
    }

    public function article(Request $request)
    {
        return ApiResponse::ok($this->service->article((int) $request->route('id'), $this->lang($request)));
    }

    public function categories(Request $request)
    {
        return ApiResponse::ok($this->service->categories($this->type($request), $this->lang($request)));
    }

    public function help(Request $request)
    {
        return ApiResponse::ok($this->service->help($this->lang($request)));
    }

    /** position 传广告位编码，多个用逗号分隔，如 home_banner,home_middle */
    public function ads(Request $request)
    {
        return ApiResponse::ok($this->service->ads(explode(',', (string) $request->query('position', '')), $this->lang($request)));
    }

    private function type(Request $request): int
    {
        return Article::TYPE_CODES[(string) $request->query('type', 'article')] ?? Article::TYPE_ARTICLE;
    }
}
