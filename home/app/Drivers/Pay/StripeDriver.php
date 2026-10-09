<?php

namespace App\Drivers\Pay;

use App\Drivers\Contracts\PayDriver;
use App\Drivers\Pay\Dto\{NotifyResult, PayRequest, PayResult, QueryResult, RefundRequest, RefundResult};
use App\Support\Exceptions\BusinessException;
use App\Support\PayAmount;
use Illuminate\Http\Request;

/** Stripe Checkout（托管收银台）。无需 SDK，直接调 REST 接口 */
class StripeDriver extends BasePayDriver implements PayDriver
{
    private const TOLERANCE = 300; // 回调时间戳容忍 5 分钟，防重放

    public function code(): string
    {
        return 'stripe';
    }

    private function base(): string
    {
        return rtrim($this->cfg('api_base', 'https://api.stripe.com'), '/');
    }

    private function client()
    {
        return $this->http()->withToken($this->cfg('secret_key'))->asForm();
    }

    public function create(PayRequest $req): PayResult
    {
        $currency = strtolower($req->currency);
        $sep = str_contains($req->returnUrl, '?') ? '&' : '?';
        $success = $req->returnUrl . $sep . 'trade_no=' . $req->tradeNo . '&session_id={CHECKOUT_SESSION_ID}';
        $cancel = $req->returnUrl . $sep . 'trade_no=' . $req->tradeNo . '&cancelled=1';

        $res = $this->client()->withHeaders(['Idempotency-Key' => 'create-' . $req->tradeNo])->post($this->base() . '/v1/checkout/sessions', [
            'mode'                                                  => 'payment',
            'success_url'                                           => $success,
            'cancel_url'                                            => $cancel,
            'client_reference_id'                                   => $req->tradeNo,
            'line_items[0][quantity]'                               => 1,
            'line_items[0][price_data][currency]'                   => $currency,
            'line_items[0][price_data][unit_amount]'                => PayAmount::toMinor($req->amount, $req->currency),
            'line_items[0][price_data][product_data][name]'         => mb_substr($req->subject, 0, 200) ?: $req->orderNo,
            'metadata[trade_no]'                                    => $req->tradeNo,
            'metadata[order_no]'                                    => $req->orderNo,
            'payment_intent_data[metadata][trade_no]'               => $req->tradeNo,
        ]);
        if (!$res->successful()) {
            $this->fail('创建 Stripe 支付', $res->json(), $res->status());
        }
        $j = $res->json();

        return new PayResult(channelTradeNo: (string) $j['id'], redirectUrl: (string) ($j['url'] ?? ''), raw: ['id' => $j['id']]);
    }

    public function verifyNotify(Request $request): NotifyResult
    {
        $payload = $request->getContent();
        $header = (string) $request->header('Stripe-Signature', '');

        $t = 0;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }

        $secret = $this->cfg('webhook_secret');
        $ok = false;
        if ($t > 0 && $secret !== '' && abs(time() - $t) <= self::TOLERANCE) {
            $expect = hash_hmac('sha256', $t . '.' . $payload, $secret);
            foreach ($sigs as $s) {
                if (hash_equals($expect, $s)) {
                    $ok = true;
                    break;
                }
            }
        }
        if (!$ok) {
            return new NotifyResult(verified: false, eventId: '', rawBody: $payload, ackBody: 'invalid signature');
        }

        return $this->parse($payload);
    }

    public function parse(string $body): NotifyResult
    {
        $e = json_decode($body, true) ?: [];
        $type = (string) ($e['type'] ?? '');
        $obj = $e['data']['object'] ?? [];
        $base = ['verified' => true, 'eventId' => (string) ($e['id'] ?? ''), 'rawBody' => $body, 'ackBody' => 'ok'];

        $isSession = str_starts_with($type, 'checkout.session.');
        if (!$isSession) {
            return new NotifyResult(...$base); // 其它事件只记录，不处理
        }

        $currency = strtoupper((string) ($obj['currency'] ?? ''));
        $amount = $currency !== '' ? PayAmount::fromMinor((string) ($obj['amount_total'] ?? 0), $currency) : '0';
        $status = match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => (($obj['payment_status'] ?? '') === 'paid' || ($obj['payment_status'] ?? '') === 'no_payment_required') ? 'success' : '',
            'checkout.session.async_payment_failed' => 'failed',
            'checkout.session.expired' => 'closed',
            default => '',
        };

        return new NotifyResult(...$base, tradeNo: (string) ($obj['client_reference_id'] ?? ($obj['metadata']['trade_no'] ?? '')),
            channelTradeNo: (string) ($obj['id'] ?? ''), status: $status, currency: $currency, amount: $amount);
    }

    public function confirm(string $tradeNo, string $channelTradeNo): QueryResult
    {
        return $this->query($tradeNo, $channelTradeNo);
    }

    public function query(string $tradeNo, string $channelTradeNo): QueryResult
    {
        if ($channelTradeNo === '') {
            return new QueryResult('pending');
        }
        $res = $this->client()->get($this->base() . '/v1/checkout/sessions/' . rawurlencode($channelTradeNo));
        if (!$res->successful()) {
            $this->fail('查询 Stripe 支付', $res->json(), $res->status());
        }
        $s = $res->json();
        $currency = strtoupper((string) ($s['currency'] ?? ''));
        $status = match (true) {
            ($s['payment_status'] ?? '') === 'paid' => 'success',
            ($s['status'] ?? '') === 'expired' => 'closed',
            default => 'pending',
        };

        return new QueryResult($status, (string) ($s['id'] ?? $channelTradeNo), $currency,
            $currency !== '' ? PayAmount::fromMinor((string) ($s['amount_total'] ?? 0), $currency) : '0', ['payment_intent' => $s['payment_intent'] ?? null]);
    }

    public function refund(RefundRequest $req): RefundResult
    {
        $sess = $this->client()->get($this->base() . '/v1/checkout/sessions/' . rawurlencode($req->channelTradeNo));
        $intent = $sess->json('payment_intent');
        if (!$sess->successful() || !$intent) {
            throw new BusinessException('退款失败：找不到该笔支付对应的 Stripe 付款记录');
        }

        $res = $this->client()->withHeaders(['Idempotency-Key' => 'refund-' . $req->refundNo])->post($this->base() . '/v1/refunds', [
            'payment_intent'      => $intent,
            'amount'              => PayAmount::toMinor($req->amount, $req->currency),
            'metadata[refund_no]' => $req->refundNo,
            'reason'              => 'requested_by_customer',
        ]);
        if (!$res->successful()) {
            $this->fail('Stripe 退款', $res->json(), $res->status());
        }
        $st = (string) $res->json('status');

        return new RefundResult(success: $st === 'succeeded', channelRefundNo: (string) $res->json('id'), pending: in_array($st, ['pending', 'requires_action'], true));
    }

    public function ping(): array
    {
        if ($this->cfg('secret_key') === '') {
            throw new BusinessException('请先填写 Secret Key');
        }
        $res = $this->client()->get($this->base() . '/v1/balance');
        if (!$res->successful()) {
            $this->fail('连接 Stripe', $res->json(), $res->status());
        }

        return ['mode' => $res->json('livemode') ? '正式环境（live）' : '测试环境（test）'];
    }
}
