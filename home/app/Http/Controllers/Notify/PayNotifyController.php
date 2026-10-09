<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Services\Common\PayService;
use Illuminate\Http\Request;

/** 支付渠道异步回调入口：不走登录，靠验签；原文先入库再处理 */
class PayNotifyController extends Controller
{
    public function __construct(private PayService $pay) {}

    public function handle(Request $request, string $channel)
    {
        [$code, $body] = $this->pay->handleNotify($channel, $request);

        return response($body, $code)->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
