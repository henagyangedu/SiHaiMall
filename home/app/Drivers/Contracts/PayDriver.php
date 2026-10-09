<?php

namespace App\Drivers\Contracts;

use App\Drivers\Pay\Dto\{NotifyResult, PayRequest, PayResult, QueryResult, RefundRequest, RefundResult};
use Illuminate\Http\Request;

interface PayDriver
{
    /** alipay / wechat / paypal / stripe ... */
    public function code(): string;

    /** 创建支付：返回收银台跳转地址或客户端调起参数 */
    public function create(PayRequest $req): PayResult;

    /** 验签并解析回调；验签失败返回 verified=false，不要抛异常 */
    public function verifyNotify(Request $request): NotifyResult;

    /** 只解析回调原文、不验签：用于后台「重新处理」（原文入库时已验过签） */
    public function parse(string $body): NotifyResult;

    /**
     * 买家支付完成后的确认：多数渠道等于查单；PayPal 需要在这里「捕获」已授权的订单。
     * 被动回调丢失、买家关闭页面时，补单任务也调用它。
     */
    public function confirm(string $tradeNo, string $channelTradeNo): QueryResult;

    /** 主动查单（只读），用于对账 */
    public function query(string $tradeNo, string $channelTradeNo): QueryResult;

    public function refund(RefundRequest $req): RefundResult;

    /** 连通性测试：用已保存的密钥调一个只读接口，失败抛 BusinessException */
    public function ping(): array;
}
