<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MemberLevelSaveRequest;
use App\Services\Admin\MemberLevelService;
use App\Support\ApiResponse;

class MemberLevelController extends BaseAdminController
{
    public function __construct(private MemberLevelService $service) {}

    public function index()
    {
        return ApiResponse::ok($this->service->list());
    }

    public function options()
    {
        return ApiResponse::ok($this->service->options());
    }

    public function store(MemberLevelSaveRequest $request)
    {
        $l = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $l->id]);
    }

    public function update(MemberLevelSaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated());

        return ApiResponse::ok();
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return ApiResponse::ok();
    }
}
