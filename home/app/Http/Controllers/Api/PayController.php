<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\PayStartRequest;
use App\Services\Api\OrderPayService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PayController extends ApiController
{
    public function __construct(private OrderPayService $service) {}

    /** 订单可用的支付方式 */
    public function channels(Request $request)
    {
        return ApiResponse::ok($this->service->channels($this->memberId($request), (string) $request->route('orderNo'), $this->source($request)));
    }

    /** 发起支付：scene 由来源端决定（h5 / mp / app / pc），不由客户端任意指定 */
    public function start(PayStartRequest $request)
    {
        return ApiResponse::ok($this->service->start(
            $this->memberId($request), (string) $request->route('orderNo'), $request->validated('channel'),
            $this->source($request), (string) $request->validated('return_url', ''),
            [
                'wx_code' => (string) $request->validated('wx_code', ''), 'client_ip' => (string) $request->ip(),
                'in_wechat' => str_contains((string) $request->userAgent(), 'MicroMessenger'),
            ]
        ));
    }

    /** 在微信里打开的 H5 用微信支付前，先跳到这里返回的地址确认付款人身份 */
    public function wechatOauthUrl(Request $request)
    {
        return ApiResponse::ok($this->service->wechatOauthUrl(mb_substr((string) $request->query('return_url', ''), 0, 500)));
    }

    /** 支付完成页确认 */
    public function confirm(Request $request)
    {
        return ApiResponse::ok($this->service->confirm($this->memberId($request), (string) $request->route('tradeNo')));
    }
}
