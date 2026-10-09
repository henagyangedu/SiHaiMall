<?php

namespace App\Services\Api;

use App\Services\Common\PayService;

/** C 端支付入口：按订单号找订单并校验归属，再交给通用支付服务 */
class OrderPayService
{
    public function __construct(private OrderService $orders, private PayService $pay) {}

    public function channels(int $memberId, string $orderNo, string $scene): array
    {
        return $this->pay->channels($this->orders->own($memberId, $orderNo), $scene);
    }

    /**
     * @param array<string,mixed> $extra 渠道需要的附加信息（小程序登录凭证、客户端 IP 等）
     * @return array{trade_no:string,redirect_url:string,client_params:array}
     */
    public function start(int $memberId, string $orderNo, string $channel, string $scene, string $returnUrl, array $extra = []): array
    {
        $order = $this->orders->own($memberId, $orderNo);

        return $this->pay->start((int) $order->id, $memberId, $channel, $scene, $returnUrl, $extra);
    }

    /** 微信里打开的 H5 付款前要先跳转的网页授权地址；不需要或不可用时 url 为空 */
    public function wechatOauthUrl(string $returnUrl): array
    {
        return ['url' => $this->pay->wechatOauthUrl($returnUrl), 'state' => PayService::WECHAT_OAUTH_STATE];
    }

    /** 支付完成页轮询：返回 success / pending / failed / closed */
    public function confirm(int $memberId, string $tradeNo): array
    {
        $r = $this->pay->confirm($tradeNo, $memberId);

        return ['status' => $r['status']];
    }
}
