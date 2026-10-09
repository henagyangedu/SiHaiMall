<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\AddressRequest;
use App\Services\Api\AddressService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AddressController extends ApiController
{
    public function __construct(private AddressService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($this->memberId($request)));
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->find($this->memberId($request), (int) $request->route('id')));
    }

    public function store(AddressRequest $request)
    {
        return ApiResponse::ok($this->service->save($this->memberId($request), 0, $request->validated()));
    }

    public function update(AddressRequest $request)
    {
        return ApiResponse::ok($this->service->save($this->memberId($request), (int) $request->route('id'), $request->validated()));
    }

    public function setDefault(Request $request)
    {
        $this->service->setDefault($this->memberId($request), (int) $request->route('id'));

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->delete($this->memberId($request), (int) $request->route('id'));

        return ApiResponse::ok();
    }
}
