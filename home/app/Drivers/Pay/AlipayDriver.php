<?php

namespace App\Drivers\Pay;

use App\Drivers\Contracts\PayDriver;
use App\Drivers\Pay\Dto\{NotifyResult, PayRequest, PayResult, QueryResult, RefundRequest, RefundResult};
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * 支付宝（开放平台网关，公钥模式，RSA2）。无需 SDK，直接调网关。
 *
 * - 场景：手机浏览器用“手机网站支付”（scene=h5），电脑浏览器用“电脑网站支付”（scene=pc），都是跳转到支付宝收银台。
 * - 只支持人民币。
 * - 签名：所有非空参数按参数名排序后拼成 k=v&k=v，用应用私钥做 SHA256-RSA 签名。
 * - 异步通知：表单参数，去掉 sign、sign_type 后同样排序拼接，用支付宝公钥验签；处理成功必须应答纯文本 success。
 * - 退款是同步出结果的，退款产生的通知只记录、不处理。
 * - 接口应答的真实性依赖 HTTPS，不再单独验签。
 */
class AlipayDriver extends BasePayDriver implements PayDriver
{
    /** 网关返回的业务码 */
    private const OK = '10000';

    private const UNKNOWN = '20000'; // 服务暂不可用：结果未知，需要稍后查询

    public function code(): string
    {
        return 'alipay';
    }

    private function gateway(): string
    {
        return $this->cfg('gateway', 'https://openapi.alipay.com/gateway.do');
    }

    // ------------------------------------------------------------------ 发起支付
    public function create(PayRequest $req): PayResult
    {
        if (strtoupper($req->currency) !== 'CNY') {
            throw new BusinessException('支付宝只支持人民币订单');
        }
        [$method, $product] = match ($req->scene) {
            'h5' => ['alipay.trade.wap.pay', 'QUICK_WAP_WAY'],
            'pc' => ['alipay.trade.page.pay', 'FAST_INSTANT_TRADE_PAY'],
            default => throw new BusinessException('支付宝暂不支持当前终端'),
        };
        if ($req->scene === 'h5' && !empty($req->extra['in_wechat'])) {
            throw new BusinessException('微信内打开的页面不能使用支付宝，请用手机浏览器打开');
        }

        $biz = [
            'out_trade_no' => $req->tradeNo,
            'total_amount' => Money::round($req->amount, 2),
            'subject'      => mb_strcut($req->subject !== '' ? $req->subject : $req->orderNo, 0, 250),
            'product_code' => $product,
        ];
        if ($req->scene === 'h5' && $req->returnUrl !== '') {
            $biz['quit_url'] = $req->returnUrl; // 买家中途放弃时返回的页面
        }
        $params = $this->signed($method, $biz, ['notify_url' => $req->notifyUrl, 'return_url' => $req->returnUrl]);

        // 跳转到网关即进入支付宝收银台；下单不返回渠道单号，支付成功后通知或查单时才有
        return new PayResult(redirectUrl: $this->gateway() . '?' . http_build_query($params));
    }

    // ------------------------------------------------------------------ 异步通知
    public function verifyNotify(Request $request): NotifyResult
    {
        $body = $request->getContent();
        parse_str($body, $p);
        $bad = new NotifyResult(verified: false, eventId: (string) ($p['notify_id'] ?? ''), rawBody: $body, ackBody: 'failure');

        $sig = base64_decode((string) ($p['sign'] ?? ''), true);
        if ($sig === false || $sig === '' || (string) ($p['app_id'] ?? '') !== $this->cfg('app_id')) {
            return $bad;
        }
        unset($p['sign'], $p['sign_type']);
        $pub = openssl_pkey_get_public(self::pem($this->cfg('alipay_public_key'), 'PUBLIC KEY'));
        if ($pub === false || openssl_verify(self::content($p), $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return $bad;
        }

        return $this->parse($body);
    }

    public function parse(string $body): NotifyResult
    {
        parse_str($body, $p);
        $base = ['verified' => true, 'eventId' => (string) ($p['notify_id'] ?? ''), 'rawBody' => $body, 'ackBody' => 'success'];

        // 退款引起的通知（带退款金额或退款请求号）：退款在发起时已同步入账，这里只记录。
        // 特别是全额退款后支付宝会通知 TRADE_CLOSED，不能当成“支付关闭”处理
        if ((string) ($p['out_biz_no'] ?? '') !== '' || bccomp((string) ($p['refund_fee'] ?? '0') ?: '0', '0', 2) > 0) {
            return new NotifyResult(...$base);
        }
        $status = match ((string) ($p['trade_status'] ?? '')) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => 'success',
            'TRADE_CLOSED' => 'closed',
            default => '',
        };

        return new NotifyResult(...$base, tradeNo: (string) ($p['out_trade_no'] ?? ''), channelTradeNo: (string) ($p['trade_no'] ?? ''),
            status: $status, currency: 'CNY', amount: Money::round((string) ($p['total_amount'] ?? '0') ?: '0', 2));
    }

    // ------------------------------------------------------------------ 查单
    public function confirm(string $tradeNo, string $channelTradeNo): QueryResult
    {
        return $this->query($tradeNo, $channelTradeNo);
    }

    public function query(string $tradeNo, string $channelTradeNo): QueryResult
    {
        $r = $this->call('alipay.trade.query', ['out_trade_no' => $tradeNo]);
        if (($r['code'] ?? '') !== self::OK) {
            // 买家还没扫码 / 登录支付宝时，支付宝侧还没有这笔交易
            if (($r['sub_code'] ?? '') === 'ACQ.TRADE_NOT_EXIST') {
                return new QueryResult('pending');
            }
            $this->failed('查询支付宝交易', $r);
        }
        $status = match ((string) ($r['trade_status'] ?? '')) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => 'success',
            // 支付宝的 TRADE_CLOSED 既可能是超时未付，也可能是付款后全额退款；后者支付单早已入账，入账逻辑会忽略
            'TRADE_CLOSED' => 'closed',
            default => 'pending',
        };

        return new QueryResult($status, (string) ($r['trade_no'] ?? ''), 'CNY', Money::round((string) ($r['total_amount'] ?? '0') ?: '0', 2), ['trade_status' => $r['trade_status'] ?? null]);
    }

    // ------------------------------------------------------------------ 退款
    public function refund(RefundRequest $req): RefundResult
    {
        $r = $this->call('alipay.trade.refund', [
            'out_trade_no'   => $req->tradeNo,
            'refund_amount'  => Money::round($req->amount, 2),
            'refund_reason'  => mb_strcut($req->reason !== '' ? $req->reason : '订单退款', 0, 250),
            'out_request_no' => $req->refundNo, // 同一个请求号重复提交不会重复退款
        ]);
        // 支付宝没有单独的退款单号，用它的交易号充当“渠道退款单号”，查询退款时要用
        $channelNo = (string) ($r['trade_no'] ?? '') ?: $req->channelTradeNo;
        if (($r['code'] ?? '') === self::OK) {
            return new RefundResult(success: true, channelRefundNo: $channelNo);
        }
        if (($r['code'] ?? '') === self::UNKNOWN) {
            return new RefundResult(success: false, channelRefundNo: $channelNo, pending: true);
        }
        $this->failed('支付宝退款', $r);
    }

    /** $channelRefundNo 是发起退款时记下的支付宝交易号 */
    public function queryRefund(string $refundNo, string $channelRefundNo): RefundResult
    {
        if ($channelRefundNo === '') {
            return new RefundResult(success: false, pending: true);
        }
        $r = $this->call('alipay.trade.fastpay.refund.query', ['trade_no' => $channelRefundNo, 'out_request_no' => $refundNo]);
        if (($r['code'] ?? '') === self::UNKNOWN) {
            return new RefundResult(success: false, channelRefundNo: $channelRefundNo, pending: true);
        }
        if (($r['code'] ?? '') !== self::OK) {
            $this->failed('查询支付宝退款', $r);
        }
        // 查得到退款金额（或状态为成功）才算退成功；应答成功但没有退款信息说明这笔退款不存在
        $done = (string) ($r['refund_status'] ?? '') === 'REFUND_SUCCESS' || bccomp((string) ($r['refund_amount'] ?? '0') ?: '0', '0', 2) > 0;

        return new RefundResult(success: $done, channelRefundNo: $channelRefundNo);
    }

    // ------------------------------------------------------------------ 连通性测试
    /** 查一笔不存在的交易：应用 ID 和私钥正确时返回“交易不存在”，否则返回验签或应用无效的错误 */
    public function ping(): array
    {
        $r = $this->call('alipay.trade.query', ['out_trade_no' => 'PING' . date('ymdHis') . random_int(1000, 9999)]);
        if (($r['code'] ?? '') === self::OK || ($r['sub_code'] ?? '') === 'ACQ.TRADE_NOT_EXIST') {
            return ['ok' => true, 'app_id' => $this->cfg('app_id')];
        }
        $this->failed('连接支付宝', $r);
    }

    // ------------------------------------------------------------------ 请求与签名
    /** 调用网关接口，返回应答里的业务部分（xxx_response） */
    private function call(string $method, array $biz): array
    {
        $params = $this->signed($method, $biz);
        $res = $this->http()->asForm()->post($this->gateway() . '?charset=utf-8', $params);
        $key = str_replace('.', '_', $method) . '_response';
        $data = $res->json($key) ?? $res->json('error_response');
        if (!is_array($data)) {
            throw new BusinessException('支付宝接口没有返回可识别的内容（HTTP ' . $res->status() . '）');
        }

        return $data;
    }

    /** 公共参数 + 业务参数 + 签名 */
    private function signed(string $method, array $biz, array $extra = []): array
    {
        $params = array_filter($extra, fn ($v) => $v !== '' && $v !== null) + [
            'app_id'      => $this->cfg('app_id'),
            'method'      => $method,
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => now('Asia/Shanghai')->format('Y-m-d H:i:s'), // 支付宝要求北京时间
            'version'     => '1.0',
            'biz_content' => json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $params['sign'] = $this->sign(self::content($params));

        return $params;
    }

    /** 待签名内容：去掉空值，按参数名排序，拼成 k=v&k=v（值不做 URL 编码） */
    public static function content(array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }

        return implode('&', $parts);
    }

    private function sign(string $content): string
    {
        $raw = $this->cfg('private_key');
        // 支付宝密钥工具生成的私钥没有 PEM 头尾，可能是 PKCS8 也可能是 PKCS1，两种都试
        $key = openssl_pkey_get_private(self::pem($raw, 'PRIVATE KEY')) ?: openssl_pkey_get_private(self::pem($raw, 'RSA PRIVATE KEY'));
        if ($key === false || !openssl_sign($content, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new BusinessException('支付宝应用私钥无效，请检查配置（应为密钥工具生成的“应用私钥”全文）');
        }

        return base64_encode($sig);
    }

    /** 密钥可能带 PEM 头尾，也可能只是一长串 Base64（支付宝后台和密钥工具给的就是这种），统一还原成标准 PEM */
    private static function pem(string $text, string $label): string
    {
        $text = trim(str_replace(["\r\n", "\r", '\n'], "\n", $text));
        if (preg_match('/^-----BEGIN ([A-Z ]+)-----(.+?)-----END [A-Z ]+-----$/s', $text, $m)) {
            [$label, $text] = [$m[1], $m[2]];
        }

        return "-----BEGIN {$label}-----\n" . chunk_split(preg_replace('/\s+/', '', $text), 64, "\n") . "-----END {$label}-----\n";
    }

    private function failed(string $what, array $r): never
    {
        throw new BusinessException("{$what}失败：" . mb_substr((string) ($r['sub_msg'] ?? $r['msg'] ?? '未知错误'), 0, 200) . (isset($r['sub_code']) ? "（{$r['sub_code']}）" : ''));
    }
}
