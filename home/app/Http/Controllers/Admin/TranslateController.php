<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\GlossarySaveRequest;
use App\Http\Requests\Admin\TranslateRequest;
use App\Http\Requests\Admin\TranslateSettingRequest;
use App\Services\Admin\GlossaryService;
use App\Services\Admin\TranslateLogService;
use App\Services\Admin\TranslateService;
use App\Services\Admin\TranslateSettingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class TranslateController extends BaseAdminController
{
    public function __construct(
        private TranslateService $service,
        private TranslateSettingService $settings,
        private GlossaryService $glossary,
        private TranslateLogService $logs,
    ) {}

    /** 翻译一批字段到一个目标语言 */
    public function translate(TranslateRequest $request)
    {
        $v = $request->validated();
        $result = $this->service->translate((array) $v['fields'], $v['from'], $v['to'], $this->admin($request));

        return ApiResponse::ok($result);
    }

    public function settings()
    {
        return ApiResponse::ok($this->settings->get() + ['base_prompt' => $this->service->basePrompt()]);
    }

    public function saveSettings(TranslateSettingRequest $request)
    {
        $this->settings->save($request->validated());

        return ApiResponse::ok(null, '保存成功');
    }

    public function glossaryIndex(Request $request)
    {
        return ApiResponse::ok($this->glossary->list($request->only(['keyword', 'lang', 'size'])));
    }

    public function glossaryStore(GlossarySaveRequest $request)
    {
        return ApiResponse::ok(['id' => $this->glossary->create($request->validated())]);
    }

    public function glossaryUpdate(GlossarySaveRequest $request, int $id)
    {
        $this->glossary->update($id, $request->validated());

        return ApiResponse::ok();
    }

    public function glossaryDestroy(int $id)
    {
        $this->glossary->delete($id);

        return ApiResponse::ok();
    }

    public function logIndex(Request $request)
    {
        return ApiResponse::ok($this->logs->list($request->only(['driver', 'status', 'date_from', 'date_to', 'size'])));
    }
}
