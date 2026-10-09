<?php

namespace App\Drivers\Pay;

use App\Drivers\Contracts\PayDriver;
use App\Drivers\Pay\Dto\{NotifyResult, PayRequest, PayResult, QueryResult, RefundRequest, RefundResult};
use App\Support\Exceptions\BusinessException;
use App\Support\PayAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** PayPal Orders v2（跳转 PayPal 收银台，买家授权后由商城「捕获」收款）。无需 SDK */
class PaypalDriver extends BasePayDriver implements PayDriver
{
    private const SUPPORTED = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];

    public function code(): string
    {
        return 'paypal';
    }

    private function base(): string
    {
        return $this->cfg('mode', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function token(): string
    {
        $key = 'paypal_token_' . md5($this->base() . $this->cfg('client_id'));

        return Cache::remember($key, 600, function () {
            // 缓存固定 10 分钟，远小于 PayPal 令牌有效期（通常数小时）
            $res = $this->http()->asForm()->withBasicAuth($this->cfg('client_id'), $this->cfg('client_secret'))
                ->post($this->base() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
            if (!$res->successful()) {
                $this->fail('获取 PayPal 令牌', $res->json(), $res->status());
            }

            return (string) $res->json('access_token');
        });
    }

    private function api(array $headers = [])
    {
        return $this->http()->withToken($this->token())->withHeaders($headers)->asJson();
    }

    public function create(PayRequest $req): PayResult
    {
        $cur = strtoupper($req->currency);
        if (!in_array($cur, self::SUPPORTED, true)) {
            throw new BusinessException("PayPal 不支持 {$cur} 币种");
        }

        $res = $this->api(['PayPal-Request-Id' => 'create-' . $req->tradeNo])->post($this->base() . '/v2/checkout/orders', [
            'intent'         => 'CAPTURE',
            'purchase_units' => [[
                'custom_id'   => $req->tradeNo,
                'invoice_id'  => $req->tradeNo,
                'description' => mb_substr($req->subject, 0, 120) ?: $req->orderNo,
                'amount'      => ['currency_code' => $cur, 'value' => PayAmount::format($req->amount, $cur)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url'          => $this->withQuery($req->returnUrl, 'trade_no=' . $req->tradeNo),
                'cancel_url'          => $this->withQuery($req->returnUrl, 'trade_no=' . $req->tradeNo . '&cancelled=1'),
                'user_action'         => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING', // 收货地址已在商城填写
            ]]],
        ]);
        if (!$res->successful()) {
            $this->fail('创建 PayPal 订单', $res->json(), $res->status());
        }

        $j = $res->json();
        $url = '';
        foreach ((array) ($j['links'] ?? []) as $l) {
            if (in_array($l['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $url = (string) $l['href'];
                break;
            }
        }
        if ($url === '') {
            throw new BusinessException('创建 PayPal 订单失败：未返回授权地址');
        }

        return new PayResult(channelTradeNo: (string) $j['id'], redirectUrl: $url, raw: ['id' => $j['id']]);
    }

    public function verifyNotify(Request $request): NotifyResult
    {
        $body = $request->getContent();
        $event = json_decode($body, true);
        $webhookId = $this->cfg('webhook_id');
        if (!is_array($event) || $webhookId === '') {
            return new NotifyResult(verified: false, eventId: '', rawBody: $body, ackBody: 'invalid');
        }

        try {
            $res = $this->api()->post($this->base() . '/v1/notifications/verify-webhook-signature', [
                'auth_algo'         => (string) $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url'          => (string) $request->header('PAYPAL-CERT-URL'),
                'transmission_id'   => (string) $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig'  => (string) $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id'        => $webhookId,
                'webhook_event'     => $event,
            ]);
            $ok = $res->successful() && $res->json('verification_status') === 'SUCCESS';
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            return new NotifyResult(verified: false, eventId: (string) ($event['id'] ?? ''), rawBody: $body, ackBody: 'invalid signature');
        }

        return $this->parse($body);
    }

    public function parse(string $body): NotifyResult
    {
        $e = json_decode($body, true) ?: [];
        $type = (string) ($e['event_type'] ?? '');
        $r = $e['resource'] ?? [];
        $base = ['verified' => true, 'eventId' => (string) ($e['id'] ?? ''), 'rawBody' => $body, 'ackBody' => 'ok'];

        if (str_starts_with($type, 'PAYMENT.CAPTURE.')) {
            $status = match ($type) {
                'PAYMENT.CAPTURE.COMPLETED' => 'success',
                'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => 'failed',
                'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
                default => '',
            };

            return new NotifyResult(...$base, tradeNo: (string) ($r['custom_id'] ?? ''),
                channelTradeNo: (string) ($r['supplementary_data']['related_ids']['order_id'] ?? ''),
                status: $status, currency: strtoupper((string) ($r['amount']['currency_code'] ?? '')), amount: (string) ($r['amount']['value'] ?? '0'),
                feeAmount: (string) ($r['seller_receivable_breakdown']['paypal_fee']['value'] ?? '0'));
        }

        if ($type === 'CHECKOUT.ORDER.APPROVED') {
            // 买家已授权但商城尚未捕获：由 PayService 调 confirm() 完成捕获
            return new NotifyResult(...$base, tradeNo: (string) ($r['purchase_units'][0]['custom_id'] ?? ''), channelTradeNo: (string) ($r['id'] ?? ''), status: 'approved');
        }

        return new NotifyResult(...$base);
    }

    public function confirm(string $tradeNo, string $channelTradeNo): QueryResult
    {
        $q = $this->query($tradeNo, $channelTradeNo);
        if (($q->raw['paypal_status'] ?? '') !== 'APPROVED') {
            return $q;
        }

        // 捕获（幂等：同一个 PayPal-Request-Id 重复调用返回同一结果）
        $res = $this->api(['PayPal-Request-Id' => 'capture-' . $tradeNo, 'Prefer' => 'return=representation'])
            ->withBody('{}', 'application/json')->post($this->base() . '/v2/checkout/orders/' . rawurlencode($channelTradeNo) . '/capture');
        if (!$res->successful()) {
            // 订单已被捕获时 PayPal 返回 422 ORDER_ALREADY_CAPTURED，此时重新查询即可
            if (($res->json('details.0.issue') ?? '') === 'ORDER_ALREADY_CAPTURED') {
                return $this->query($tradeNo, $channelTradeNo);
            }
            $this->fail('PayPal 收款确认', $res->json(), $res->status());
        }

        return $this->toQuery($res->json(), $channelTradeNo);
    }

    public function query(string $tradeNo, string $channelTradeNo): QueryResult
    {
        if ($channelTradeNo === '') {
            return new QueryResult('pending');
        }
        $res = $this->api()->get($this->base() . '/v2/checkout/orders/' . rawurlencode($channelTradeNo));
        if (!$res->successful()) {
            $this->fail('查询 PayPal 订单', $res->json(), $res->status());
        }

        return $this->toQuery($res->json(), $channelTradeNo);
    }

    private function toQuery(array $o, string $orderId): QueryResult
    {
        $st = (string) ($o['status'] ?? '');
        $cap = $o['purchase_units'][0]['payments']['captures'][0] ?? null;
        $amount = $cap['amount'] ?? $o['purchase_units'][0]['amount'] ?? [];

        $status = match (true) {
            $st === 'COMPLETED' && ($cap['status'] ?? '') === 'COMPLETED' => 'success',
            $st === 'COMPLETED' && in_array($cap['status'] ?? '', ['DECLINED', 'FAILED'], true) => 'failed',
            $st === 'VOIDED' => 'closed',
            default => 'pending', // CREATED / APPROVED / PAYER_ACTION_REQUIRED / 捕获 PENDING
        };

        return new QueryResult($status, $orderId, strtoupper((string) ($amount['currency_code'] ?? '')), (string) ($amount['value'] ?? '0'),
            ['paypal_status' => $st, 'capture_id' => $cap['id'] ?? null]);
    }

    public function refund(RefundRequest $req): RefundResult
    {
        $q = $this->query($req->tradeNo, $req->channelTradeNo);
        $captureId = $q->raw['capture_id'] ?? null;
        if (!$captureId) {
            throw new BusinessException('退款失败：找不到该笔支付对应的 PayPal 收款记录');
        }

        $cur = strtoupper($req->currency);
        $res = $this->api(['PayPal-Request-Id' => 'refund-' . $req->refundNo])->post($this->base() . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', [
            'amount'        => ['currency_code' => $cur, 'value' => PayAmount::format($req->amount, $cur)],
            'invoice_id'    => $req->refundNo,
            'note_to_payer' => mb_substr($req->reason, 0, 255),
        ]);
        if (!$res->successful()) {
            $this->fail('PayPal 退款', $res->json(), $res->status());
        }
        $st = (string) $res->json('status');

        return new RefundResult(success: $st === 'COMPLETED', channelRefundNo: (string) $res->json('id'), pending: $st === 'PENDING');
    }

    public function ping(): array
    {
        if ($this->cfg('client_id') === '' || $this->cfg('client_secret') === '') {
            throw new BusinessException('请先填写 Client ID 和 Client Secret');
        }
        Cache::forget('paypal_token_' . md5($this->base() . $this->cfg('client_id')));
        $this->token(); // 能拿到令牌即说明密钥与环境（sandbox/live）匹配

        return ['mode' => $this->cfg('mode', 'sandbox') === 'live' ? '正式环境（live）' : '沙箱环境（sandbox）'];
    }

    private function withQuery(string $url, string $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }
}
