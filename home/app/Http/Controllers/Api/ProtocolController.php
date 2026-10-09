<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\ProtocolService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProtocolController extends ApiController
{
    public function __construct(private ProtocolService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($this->lang($request)));
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->detail((string) $request->route('key'), $this->lang($request)));
    }
}
