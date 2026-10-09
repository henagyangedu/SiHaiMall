<?php

namespace App\Services\Common;

use App\Drivers\DriverManager;
use App\Drivers\Pay\Dto\NotifyResult;
use App\Drivers\Pay\Dto\PayRequest;
use App\Drivers\Pay\WechatDriver;
use App\Models\DriverConfig;
use App\Models\Order;
use App\Models\PayNotifyLog;
use App\Models\PayTrade;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 支付编排：发起支付 → 回调/主动确认 → 订单置为已支付。
 * 原则：
 *  1. 回调原文先入库再处理，(渠道, 事件ID) 唯一，渠道重复推送不会重复处理；
 *  2. 以「支付单号」为锁（行锁）+ 状态检查保证只入账一次；
 *  3. 金额、币种必须与支付单、订单完全一致才改订单，不一致/订单已关闭/重复付款 → 记为「需人工处理」，不自动吞掉；
 *  4. 渠道返回的任何字段都不直接信任：订单、金额以本地库为准。
 */
class PayService
{
    public function __construct(private DriverManager $drivers, private OrderPaidService $paid, private OrderRefundService $refunds) {}

    /**
     * 渠道自身的限制（和后台配置无关）：能用在哪些来源端、只收哪些币种。没写的表示不限。
     * 微信支付有小程序、手机浏览器、电脑扫码三种付法，只收人民币；支付宝只有手机和电脑浏览器两种、只收人民币；跳转收银台的渠道在小程序里打不开。
     */
    private const LIMITS = [
        'alipay' => ['scenes' => ['h5', 'pc'], 'currencies' => ['CNY']],
        'wechat' => ['scenes' => ['mp', 'h5', 'pc'], 'currencies' => ['CNY']],
        'stripe' => ['scenes' => ['h5', 'pc', 'app']],
        'paypal' => ['scenes' => ['h5', 'pc', 'app']],
    ];

    /** 每次发起都重新生成支付请求的渠道（微信的支付凭证、支付宝带时间戳签名的跳转地址都是几分钟就过期，不能复用上一次的） */
    private const NO_REUSE = ['wechat', 'alipay'];

    // ------------------------------------------------------------------ 可用渠道
    /** 订单可用的支付渠道（已启用、支持该币种、能在当前来源端使用）。$scene 为空表示不按来源端筛选 */
    public function channels(Order $order, string $scene = ''): array
    {
        $out = [];
        foreach (DriverConfig::query()->where('type', 'pay')->where('is_enabled', 1)->orderBy('sort')->get() as $row) {
            $limit = self::LIMITS[$row->driver] ?? [];
            if (($scene !== '' && isset($limit['scenes']) && !in_array($scene, $limit['scenes'], true))
                || (isset($limit['currencies']) && !in_array(strtoupper($order->currency), $limit['currencies'], true))) {
                continue;
            }
            $cfg = $row->config ?? [];
            // 小程序里用微信支付必须配了小程序 AppID
            if ($row->driver === 'wechat' && $scene === 'mp' && trim((string) ($cfg['mp_app_id'] ?? '')) === '') {
                continue;
            }
            $allow = array_filter(array_map('trim', explode(',', strtoupper((string) ($cfg['currencies'] ?? '')))));
            if ($allow !== [] && !in_array(strtoupper($order->currency), $allow, true)) {
                continue;
            }
            $out[] = ['channel' => $row->driver, 'name' => $row->name];
        }

        return $out;
    }

    /** 微信网页授权跳回页面时带的 state，页面靠它认出“这是为了付款去拿的 code” */
    public const WECHAT_OAUTH_STATE = 'gmwxpay';

    /**
     * 在微信里打开的 H5 用微信支付前，页面要先跳到这个地址拿付款人的 code（拿到后随发起支付一起提交）。
     * 没启用微信支付或没配公众号时返回空串，页面照常发起支付即可（会得到“暂不支持”的提示）。
     */
    public function wechatOauthUrl(string $returnUrl): string
    {
        if ($returnUrl === '') {
            throw new BusinessException('支付完成跳转地址不在允许范围内');
        }
        $returnUrl = $this->safeReturnUrl($returnUrl);
        $row = DriverConfig::query()->where('type', 'pay')->where('driver', 'wechat')->where('is_enabled', 1)->first();
        if (!$row) {
            return '';
        }
        $driver = $this->drivers->payFromConfig('wechat', $row->config ?? []);

        return $driver instanceof WechatDriver ? $driver->oauthUrl($returnUrl, self::WECHAT_OAUTH_STATE) : '';
    }

    // ------------------------------------------------------------------ 发起支付
    /**
     * @param array<string,mixed> $extra 渠道需要的附加信息：wx_code（小程序登录凭证）、client_ip、in_wechat 等
     * @return array{trade_no:string,redirect_url:string,client_params:array}
     */
    public function start(int $orderId, int $memberId, string $channel, string $scene, string $returnUrl = '', array $extra = []): array
    {
        // 小程序里付款不会跳转，没有回跳地址可校验
        $returnUrl = $scene === 'mp' ? '' : $this->safeReturnUrl($returnUrl);

        /** @var PayTrade $trade */
        [$trade, $order, $reuse] = DB::transaction(function () use ($orderId, $memberId, $channel, $scene) {
            $order = Order::query()->lockForUpdate()->find($orderId) ?? throw new BusinessException('订单不存在');
            if ($memberId > 0 && (int) $order->member_id !== $memberId) {
                throw new BusinessException('订单不存在');
            }
            if ((int) $order->status !== OrderStatus::PENDING || (int) $order->pay_status !== OrderStatus::PAY_UNPAID) {
                throw new BusinessException('订单当前不能支付');
            }
            if ($order->expire_at && $order->expire_at->isPast()) {
                throw new BusinessException('订单已超时，请重新下单');
            }
            if (!in_array($channel, array_column($this->channels($order, $scene), 'channel'), true)) {
                throw new BusinessException('该支付方式暂不可用或不支持当前币种');
            }

            // 20 分钟内同渠道同金额的待支付单直接复用，避免用户反复点击产生一堆收银台会话
            $old = PayTrade::query()->where('order_id', $order->id)->where('channel', $channel)->where('trade_type', 1)
                ->where('status', PayTrade::PENDING)->where('amount', $order->pay_amount)->where('currency', $order->currency)
                ->where('created_at', '>', now()->subMinutes(20))->orderByDesc('id')->first();
            if ($old && ($old->rawData()['redirect_url'] ?? '') !== '' && !in_array($channel, self::NO_REUSE, true)) {
                return [$old, $order, true];
            }

            // 渠道单号先用商城支付单号占位：留空会撞 (channel, channel_trade_no, trade_type) 唯一约束
            $tradeNo = $this->makeTradeNo();
            $trade = PayTrade::query()->create([
                'trade_no' => $tradeNo, 'order_id' => $order->id, 'member_id' => $order->member_id, 'channel' => $channel,
                'channel_trade_no' => $tradeNo, 'trade_type' => 1, 'currency' => $order->currency, 'amount' => $order->pay_amount,
                'status' => PayTrade::PENDING,
            ]);

            return [$trade, $order, false];
        });

        if ($reuse) {
            return ['trade_no' => $trade->trade_no, 'redirect_url' => (string) $trade->rawData()['redirect_url'], 'client_params' => []];
        }

        // 调渠道接口（网络请求）放在事务外，避免长时间占用订单行锁
        try {
            $res = $this->drivers->pay($channel)->create(new PayRequest(
                tradeNo: $trade->trade_no, orderNo: $order->order_no, currency: $order->currency, amount: (string) $order->pay_amount,
                subject: $this->subject($order), scene: $scene, returnUrl: $returnUrl,
                notifyUrl: rtrim((string) config('app.url'), '/') . '/notify/pay/' . $channel,
                extra: $extra,
            ));
        } catch (\Throwable $e) {
            $trade->update(['status' => PayTrade::FAILED, 'raw_response' => json_encode(['flag' => '', 'error' => mb_substr($e->getMessage(), 0, 300)], JSON_UNESCAPED_UNICODE)]);

            throw $e instanceof BusinessException ? $e : new BusinessException('发起支付失败，请稍后重试');
        }

        $trade->update([
            'channel_trade_no' => $res->channelTradeNo !== '' ? $res->channelTradeNo : $trade->trade_no,
            'raw_response'     => json_encode(['redirect_url' => $res->redirectUrl], JSON_UNESCAPED_UNICODE),
        ]);

        return ['trade_no' => $trade->trade_no, 'redirect_url' => $res->redirectUrl, 'client_params' => $res->clientParams];
    }

    // ------------------------------------------------------------------ 买家返回后的主动确认
    /** C 端支付完成页调用：向渠道确认并入账，返回 success / pending / failed / closed */
    public function confirm(string $tradeNo, int $memberId = 0): array
    {
        $trade = PayTrade::query()->where('trade_no', $tradeNo)->first() ?? throw new BusinessException('支付单不存在');
        if ($memberId > 0 && (int) $trade->member_id !== $memberId) {
            throw new BusinessException('支付单不存在');
        }
        $status = $this->poll($trade);

        return ['status' => $status, 'order_id' => (int) $trade->order_id];
    }

    /** 向渠道确认一笔支付单并入账；返回渠道侧状态 */
    private function poll(PayTrade $trade): string
    {
        if ((int) $trade->status === PayTrade::SUCCESS) {
            return 'success';
        }
        $q = $this->drivers->payForNotify($trade->channel)->confirm($trade->trade_no, $trade->channelNo());
        if ($q->status === 'pending') {
            return 'pending';
        }

        $logId = $this->openLog($trade->channel, "poll:{$trade->trade_no}:{$q->status}", $trade->trade_no, [], json_encode($q->raw, JSON_UNESCAPED_UNICODE) ?: '', true);
        if ($logId !== null) {
            $this->finish($logId, fn () => $this->settle($trade->trade_no, $q->status, $q->channelTradeNo, $q->currency, $q->amount, '0'));
        }

        return $q->status;
    }

    // ------------------------------------------------------------------ 渠道回调
    /** @return array{0:int,1:string} HTTP 状态码、应答内容 */
    public function handleNotify(string $channel, Request $request): array
    {
        $body = $request->getContent();
        try {
            $driver = $this->drivers->payForNotify($channel);
            $r = $driver->verifyNotify($request);
        } catch (\Throwable $e) {
            report($e);

            return [500, 'error'];
        }

        $eventId = $r->eventId !== '' ? $r->eventId : 'noid-' . sha1($body);
        $logId = $this->openLog($channel, $eventId, $r->tradeNo, $this->safeHeaders($request), $body, $r->verified);

        if (!$r->verified) {
            if ($logId !== null) {
                PayNotifyLog::query()->whereKey($logId)->update(['handle_status' => PayNotifyLog::FAIL, 'error_msg' => '验签失败']);
            }

            return [400, $r->ackBody];
        }
        if ($logId === null) {
            return [200, $r->ackBody]; // 已处理过的重复推送
        }

        try {
            $this->finish($logId, fn () => $this->dispatch($channel, $r));
        } catch (\Throwable $e) {
            report($e);

            return [500, 'error']; // 让渠道稍后重试
        }

        return [200, $r->ackBody];
    }

    /** 后台「重新处理」：用入库的原文重新解析并处理（原文已在入库时验过签） */
    public function reprocess(int $logId): void
    {
        $log = PayNotifyLog::query()->find($logId) ?? throw new BusinessException('回调记录不存在');
        if ((int) $log->verify_ok !== 1) {
            throw new BusinessException('验签未通过的回调不能重新处理');
        }
        if ((int) $log->handle_status === PayNotifyLog::OK) {
            throw new BusinessException('该回调已处理成功');
        }

        $r = $this->drivers->payForNotify($log->channel)->parse((string) $log->body);
        $this->finish($logId, fn () => $this->dispatch($log->channel, $r));
    }

    /** 事件分发：success / failed / closed / approved（PayPal 待捕获） */
    private function dispatch(string $channel, NotifyResult $r): array
    {
        // 退款结果：带着商城售后单号的才处理（在渠道后台手工发起的退款没有单号，只记录）
        if ($r->status === 'refunded' || $r->status === 'refund_failed') {
            if ($r->refundNo === '') {
                return [PayNotifyLog::OK, '退款事件已记录（不是商城发起的退款，未自动处理）'];
            }
            [$ok, $msg] = $r->status === 'refunded'
                ? $this->refunds->completeByNotify($r->refundNo, $channel, $r->channelRefundNo, $r->currency, $r->amount)
                : $this->refunds->failByNotify($r->refundNo, $channel);

            return [$ok ? PayNotifyLog::OK : PayNotifyLog::FAIL, $msg];
        }
        if ($r->tradeNo === '' || $r->status === '') {
            return [PayNotifyLog::OK, '非支付结果事件，已记录'];
        }
        if ($r->status === 'approved') {
            $trade = PayTrade::query()->where('trade_no', $r->tradeNo)->first();
            if (!$trade) {
                return [PayNotifyLog::FAIL, '未找到支付单：' . $r->tradeNo];
            }
            $q = $this->drivers->payForNotify($channel)->confirm($r->tradeNo, $r->channelTradeNo ?: $trade->channelNo());

            return $this->settle($r->tradeNo, $q->status, $q->channelTradeNo, $q->currency, $q->amount, '0');
        }

        return $this->settle($r->tradeNo, $r->status, $r->channelTradeNo, $r->currency, $r->amount, $r->feeAmount);
    }

    // ------------------------------------------------------------------ 入账核心
    /**
     * @return array{0:int,1:string} [处理状态 PayNotifyLog::OK|FAIL, 说明]
     * 只有「金额币种一致 + 订单仍待支付」才会改订单；其它情况款项已收到但不动订单，记 flag 等人工退款。
     */
    private function settle(string $tradeNo, string $status, string $channelTradeNo, string $currency, string $amount, string $fee): array
    {
        return DB::transaction(function () use ($tradeNo, $status, $channelTradeNo, $currency, $amount, $fee) {
            $trade = PayTrade::query()->where('trade_no', $tradeNo)->lockForUpdate()->first();
            if (!$trade) {
                return [PayNotifyLog::FAIL, '未找到支付单：' . $tradeNo];
            }
            if ((int) $trade->status === PayTrade::SUCCESS) {
                return [PayNotifyLog::OK, '支付单已入账，忽略重复通知'];
            }

            if ($status === 'failed' || $status === 'closed') {
                if ((int) $trade->status === PayTrade::PENDING) {
                    $trade->update(['status' => $status === 'failed' ? PayTrade::FAILED : PayTrade::CLOSED]);
                }

                return [PayNotifyLog::OK, $status === 'failed' ? '支付失败，已记录' : '支付会话已过期，已关闭'];
            }
            if ($status !== 'success') {
                return [PayNotifyLog::OK, '未知状态，已忽略：' . $status];
            }

            // ---- 以下为「渠道确认已收款」
            if (strtoupper($currency) !== strtoupper($trade->currency) || bccomp($this->num($amount), (string) $trade->amount, 2) !== 0) {
                $this->flag($trade, "渠道到账 {$amount} {$currency} 与支付单 {$trade->amount} {$trade->currency} 不一致，请人工核对");

                return [PayNotifyLog::FAIL, '金额或币种不一致，未入账，需人工核对'];
            }

            $trade->update([
                'status' => PayTrade::SUCCESS, 'paid_at' => now(), 'fee_amount' => $this->num($fee),
                'channel_trade_no' => $channelTradeNo !== '' ? $channelTradeNo : $trade->channel_trade_no,
            ]);

            $order = Order::query()->lockForUpdate()->find($trade->order_id);
            $why = $this->notPayableReason($order, $trade);
            if ($why !== null) {
                $this->flag($trade, $why . '。款项已收到，请在渠道后台或售后里退款');

                return [PayNotifyLog::FAIL, $why . '，需人工退款'];
            }

            try {
                $this->paid->markPaid((int) $order->id, $trade->channel, OrderStatus::OP_SYSTEM, 0, "支付成功：{$trade->channel} {$trade->channelNo()}");
            } catch (BusinessException $e) {
                $this->flag($trade, '订单处理失败：' . $e->getMessage() . '。款项已收到，请人工退款或处理');

                return [PayNotifyLog::FAIL, '订单处理失败：' . $e->getMessage()];
            }

            return [PayNotifyLog::OK, '支付成功，订单已更新'];
        });
    }

    private function notPayableReason(?Order $order, PayTrade $trade): ?string
    {
        if (!$order) {
            return '订单不存在';
        }
        if ((int) $order->pay_status === OrderStatus::PAY_PAID) {
            return '订单已通过其它支付单支付（重复付款）';
        }
        if ((int) $order->status !== OrderStatus::PENDING) {
            return '订单已' . (OrderStatus::TEXT[(int) $order->status] ?? '变更');
        }
        if ($order->currency !== $trade->currency || bccomp((string) $order->pay_amount, (string) $trade->amount, 2) !== 0) {
            return '订单金额在发起支付后被修改';
        }

        return null;
    }

    private function flag(PayTrade $trade, string $msg): void
    {
        $raw = $trade->rawData();
        $raw['flag'] = $msg;
        $trade->update(['raw_response' => json_encode($raw, JSON_UNESCAPED_UNICODE)]);
    }

    // ------------------------------------------------------------------ 补单（定时任务）
    /** 查询「渠道可能已收款但回调丢失」的待支付单，返回入账笔数。同时关闭超过 24 小时仍未支付的支付单 */
    public function compensate(int $limit = 100): int
    {
        $n = 0;
        PayTrade::query()->where('trade_type', 1)->where('status', PayTrade::PENDING)
            ->where('created_at', '<', now()->subMinutes(2))->where('created_at', '>', now()->subDay())
            ->orderBy('id')->limit($limit)->get()
            ->each(function (PayTrade $t) use (&$n) {
                try {
                    if ($this->poll($t) === 'success') {
                        $n++;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        PayTrade::query()->where('trade_type', 1)->where('status', PayTrade::PENDING)->where('created_at', '<', now()->subDay())->update(['status' => PayTrade::CLOSED]);

        return $n;
    }

    // ------------------------------------------------------------------ 回调日志
    /** 写入回调日志；返回需要处理的日志 ID，已成功处理过的重复事件返回 null */
    private function openLog(string $channel, string $eventId, string $tradeNo, array $headers, string $body, bool $verified): ?int
    {
        $row = PayNotifyLog::query()->where('channel', $channel)->where('event_id', $eventId)->first();
        if ($row) {
            if (in_array((int) $row->handle_status, [PayNotifyLog::OK, PayNotifyLog::DUP], true)) {
                return null;
            }

            return (int) $row->id; // 之前失败或未完成：渠道重试时继续处理同一条
        }

        try {
            return (int) PayNotifyLog::query()->create([
                'channel' => $channel, 'event_id' => mb_substr($eventId, 0, 128), 'trade_no' => mb_substr($tradeNo, 0, 40),
                'headers' => json_encode($headers, JSON_UNESCAPED_UNICODE), 'body' => $body, 'verify_ok' => $verified ? 1 : 0,
                'handle_status' => PayNotifyLog::WAIT, 'created_at' => now(),
            ])->id;
        } catch (\Illuminate\Database\QueryException $e) {
            return null; // 并发下另一个请求刚写入同一事件
        }
    }

    /** 执行处理并回写日志；处理抛异常时记录错误并继续向上抛（让渠道重试） */
    private function finish(int $logId, \Closure $work): void
    {
        try {
            [$st, $msg] = $work();
            PayNotifyLog::query()->whereKey($logId)->update(['handle_status' => $st, 'error_msg' => $st === PayNotifyLog::OK ? '' : mb_substr($msg, 0, 500)]);
        } catch (\Throwable $e) {
            PayNotifyLog::query()->whereKey($logId)->update(['handle_status' => PayNotifyLog::FAIL, 'error_msg' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        }
    }

    // ------------------------------------------------------------------ 工具
    private function safeHeaders(Request $request): array
    {
        $keep = ['content-type', 'user-agent', 'stripe-signature', 'paypal-transmission-id', 'paypal-transmission-time', 'paypal-auth-algo'];
        $out = [];
        foreach ($keep as $k) {
            if ($request->headers->has($k)) {
                $out[$k] = (string) $request->headers->get($k);
            }
        }

        return $out;
    }

    private function subject(Order $order): string
    {
        $title = (string) DB::table('order_item')->where('order_id', $order->id)->orderBy('id')->value('title');
        $cnt = DB::table('order_item')->where('order_id', $order->id)->count();

        return mb_substr($title, 0, 80) . ($cnt > 1 ? " 等{$cnt}件" : '');
    }

    /** 只允许跳回本站域名或配置的白名单域名，防止被利用做钓鱼跳转 */
    private function safeReturnUrl(string $url): string
    {
        $default = rtrim((string) config('app.url'), '/') . '/pay/return';
        if ($url === '') {
            return $default;
        }
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        $allow = array_filter(array_map('trim', explode(',', strtolower(implode(',', (array) config('mall.pay_return_hosts', []))))));
        $allow[] = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if (!in_array($p['scheme'] ?? '', ['http', 'https'], true) || $host === '' || !in_array($host, $allow, true)) {
            throw new BusinessException('支付完成跳转地址不在允许范围内');
        }

        return $url;
    }

    private function makeTradeNo(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $no = 'PT' . date('ymdHis') . random_int(100000, 999999);
            if (!PayTrade::query()->where('trade_no', $no)->exists()) {
                return $no;
            }
        }
        throw new BusinessException('系统繁忙，请重试');
    }

    private function num(string $v): string
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $v) ? bcadd($v, '0', 2) : '0.00';
    }
}
