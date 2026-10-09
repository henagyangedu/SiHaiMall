<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ArticleCategorySaveRequest;
use App\Http\Requests\Admin\ArticleSaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\ArticleService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 文章 / 公告 / 帮助中心共用。内容类型 type 由路由的 defaults 指定（1 文章 2 公告 3 帮助） */
class ArticleController extends BaseAdminController
{
    public function __construct(private ArticleService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($this->type($request), $request->only(['keyword', 'category_id', 'status', 'size'])));
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->detail($this->type($request), (int) $request->route('id')));
    }

    public function store(ArticleSaveRequest $request)
    {
        $article = $this->service->save($this->type($request), 0, $request->validated());

        return ApiResponse::ok(['id' => $article->id]);
    }

    public function update(ArticleSaveRequest $request)
    {
        $this->service->save($this->type($request), (int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function status(StatusRequest $request)
    {
        $this->service->setStatus($this->type($request), (int) $request->route('id'), (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    /** 置顶 / 取消置顶（复用状态请求：status=1 置顶） */
    public function top(StatusRequest $request)
    {
        $this->service->setTop($this->type($request), (int) $request->route('id'), (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->delete($this->type($request), (int) $request->route('id'));

        return ApiResponse::ok();
    }

    // ---- 分类（文章、帮助中心）
    public function categories(Request $request)
    {
        return ApiResponse::ok($this->service->categories($this->type($request)));
    }

    public function storeCategory(ArticleCategorySaveRequest $request)
    {
        $cat = $this->service->saveCategory($this->type($request), 0, $request->validated());

        return ApiResponse::ok(['id' => $cat->id]);
    }

    public function updateCategory(ArticleCategorySaveRequest $request)
    {
        $this->service->saveCategory($this->type($request), (int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function destroyCategory(Request $request)
    {
        $this->service->deleteCategory($this->type($request), (int) $request->route('id'));

        return ApiResponse::ok();
    }

    private function type(Request $request): int
    {
        return (int) $request->route('type');
    }
}
