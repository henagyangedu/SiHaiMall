<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ShippingTemplateSaveRequest;
use App\Services\Admin\ShippingTemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ShippingTemplateController extends BaseAdminController
{
    public function __construct(private ShippingTemplateService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'size'])));
    }

    public function options()
    {
        return ApiResponse::ok($this->service->options());
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->detail((int) $request->route('id')));
    }

    public function store(ShippingTemplateSaveRequest $request)
    {
        $template = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $template->id]);
    }

    public function update(ShippingTemplateSaveRequest $request)
    {
        $this->service->update((int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->delete((int) $request->route('id'));

        return ApiResponse::ok();
    }
}
