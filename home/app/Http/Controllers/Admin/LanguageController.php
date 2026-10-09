<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\LanguageSaveRequest;
use App\Services\Admin\LanguageService;
use App\Support\ApiResponse;

class LanguageController extends BaseAdminController
{
    public function __construct(private LanguageService $service) {}

    public function index()
    {
        return ApiResponse::ok($this->service->list());
    }

    public function options()
    {
        return ApiResponse::ok($this->service->options());
    }

    public function store(LanguageSaveRequest $request)
    {
        $lang = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $lang->id]);
    }

    public function update(LanguageSaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated());

        return ApiResponse::ok();
    }

    public function setDefault(int $id)
    {
        $this->service->setDefault($id);

        return ApiResponse::ok();
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return ApiResponse::ok();
    }
}
