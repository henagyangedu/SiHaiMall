<?php

namespace App\Drivers\Pay;

use App\Drivers\Contracts\PayDriver;
use App\Drivers\Pay\Dto\{NotifyResult, PayRequest, PayResult, QueryResult, RefundRequest, RefundResult};
use App\Support\Exceptions\BusinessException;
use App\Support\PayAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 微信支付（API v3，直连商户）。无需 SDK，直接调 REST 接口。
 *
 * - 场景：小程序里用 JSAPI 支付（scene=mp，付款时由小程序 wx.login 的 code 换 openid）；手机浏览器用 H5 支付（scene=h5）。
 * - 只支持人民币。
 * - 请求签名：商户 API 证书私钥，SHA256-RSA，签名串为 方法\n路径\n时间戳\n随机串\n报文\n。
 * - 回调验签：微信支付公钥（商户平台“微信支付公钥”模式，不是平台证书）；回调内容用 APIv3 密钥做 AES-256-GCM 解密。
 * - 接口应答的真实性依赖 HTTPS，不再单独验签。
 */
class WechatDriver extends BasePayDriver implements PayDriver
{
    private const TOLERANCE = 300; // 回调时间戳容忍 5 分钟，防重放

    public function code(): string
    {
        return 'wechat';
    }

    private function base(): string
    {
        return rtrim($this->cfg('api_base', 'https://api.mch.weixin.qq.com'), '/');
    }

    // ------------------------------------------------------------------ 发起支付
    public function create(PayRequest $req): PayResult
    {
        if (strtoupper($req->currency) !== 'CNY') {
            throw new BusinessException('微信支付只支持人民币订单');
        }
        $body = [
            'mchid'        => $this->cfg('mch_id'),
            'description'  => mb_strcut($req->subject !== '' ? $req->subject : $req->orderNo, 0, 120),
            'out_trade_no' => $req->tradeNo,
            'notify_url'   => $req->notifyUrl,
            'amount'       => ['total' => (int) PayAmount::toMinor($req->amount, 'CNY'), 'currency' => 'CNY'],
        ];

        if ($req->scene === 'mp') {
            $appId = $this->cfg('mp_app_id');
            if ($appId === '') {
                throw new BusinessException('微信支付还没有配置小程序 AppID');
            }
            $openid = (string) ($req->extra['openid'] ?? '') ?: $this->openid((string) ($req->extra['wx_code'] ?? ''));
            $j = $this->call('POST', '/v3/pay/transactions/jsapi', $body + ['appid' => $appId, 'payer' => ['openid' => $openid]], '发起微信支付');
            $prepayId = (string) ($j['prepay_id'] ?? '');
            if ($prepayId === '') {
                throw new BusinessException('发起微信支付失败，请稍后重试');
            }

            return new PayResult(channelTradeNo: $prepayId, clientParams: $this->clientParams($appId, $prepayId), raw: ['prepay_id' => $prepayId]);
        }

        if ($req->scene === 'h5') {
            if (!empty($req->extra['in_wechat'])) {
                throw new BusinessException('微信内打开的页面暂不支持直接付款，请用手机浏览器打开，或在小程序里付款');
            }
            $appId = $this->cfg('h5_app_id') ?: $this->cfg('mp_app_id');
            if ($appId === '') {
                throw new BusinessException('微信支付还没有配置 AppID');
            }
            $j = $this->call('POST', '/v3/pay/transactions/h5', $body + [
                'appid'      => $appId,
                'scene_info' => ['payer_client_ip' => (string) ($req->extra['client_ip'] ?? '') ?: '127.0.0.1', 'h5_info' => ['type' => 'Wap']],
            ], '发起微信支付');
            $url = (string) ($j['h5_url'] ?? '');
            if ($url === '') {
                throw new BusinessException('发起微信支付失败，请稍后重试');
            }
            if ($req->returnUrl !== '') {
                $back = $req->returnUrl . (str_contains($req->returnUrl, '?') ? '&' : '?') . 'trade_no=' . $req->tradeNo;
                $url .= (str_contains($url, '?') ? '&' : '?') . 'redirect_url=' . rawurlencode($back);
            }

            // H5 下单不返回渠道单号，支付成功后回调或查单时才有
            return new PayResult(redirectUrl: $url);
        }

        throw new BusinessException('微信支付暂不支持当前终端');
    }

    /** 小程序调起支付的参数（wx.requestPayment） */
    private function clientParams(string $appId, string $prepayId): array
    {
        $ts = (string) time();
        $nonce = Str::random(32);
        $package = 'prepay_id=' . $prepayId;

        return [
            'timeStamp' => $ts, 'nonceStr' => $nonce, 'package' => $package, 'signType' => 'RSA',
            'paySign' => $this->sign("{$appId}\n{$ts}\n{$nonce}\n{$package}\n"),
        ];
    }

    /** 用小程序 wx.login 拿到的 code 换 openid（只用于这一次付款，不保存） */
    private function openid(string $code): string
    {
        if ($code === '') {
            throw new BusinessException('请在微信小程序里重新发起支付');
        }
        $res = $this->http()->get(rtrim($this->cfg('wx_api_base', 'https://api.weixin.qq.com'), '/') . '/sns/jscode2session', [
            'appid' => $this->cfg('mp_app_id'), 'secret' => $this->cfg('mp_app_secret'), 'js_code' => $code, 'grant_type' => 'authorization_code',
        ]);
        $openid = (string) $res->json('openid');
        if ($openid === '') {
            Log::warning('微信 code 换 openid 失败', ['errcode' => $res->json('errcode'), 'errmsg' => mb_substr((string) $res->json('errmsg'), 0, 200)]);
            throw new BusinessException('微信登录状态已失效，请重新发起支付');
        }

        return $openid;
    }

    // ------------------------------------------------------------------ 回调
    public function verifyNotify(Request $request): NotifyResult
    {
        $body = $request->getContent();
        $eventId = (string) (json_decode($body, true)['id'] ?? '');
        $bad = new NotifyResult(verified: false, eventId: $eventId, rawBody: $body, ackBody: '{"code":"FAIL","message":"invalid signature"}');

        $ts = (string) $request->header('Wechatpay-Timestamp');
        $nonce = (string) $request->header('Wechatpay-Nonce');
        $sig = base64_decode((string) $request->header('Wechatpay-Signature'), true);
        if ($ts === '' || $nonce === '' || $sig === false || $sig === '' || abs(time() - (int) $ts) > self::TOLERANCE) {
            return $bad;
        }
        // 配了公钥 ID 时核对回调用的是不是这把公钥（换公钥期间能及早发现配置没跟上）
        $keyId = $this->cfg('public_key_id');
        if ($keyId !== '' && (string) $request->header('Wechatpay-Serial') !== $keyId) {
            return $bad;
        }
        $pub = openssl_pkey_get_public(self::pem($this->cfg('public_key')));
        if ($pub === false || openssl_verify("{$ts}\n{$nonce}\n{$body}\n", $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            return $bad;
        }

        return $this->parse($body);
    }

    public function parse(string $body): NotifyResult
    {
        $e = json_decode($body, true) ?: [];
        $type = (string) ($e['event_type'] ?? '');
        $base = ['verified' => true, 'eventId' => (string) ($e['id'] ?? ''), 'rawBody' => $body, 'ackBody' => '{"code":"SUCCESS","message":"OK"}'];
        if (!str_starts_with($type, 'TRANSACTION.') && !str_starts_with($type, 'REFUND.')) {
            return new NotifyResult(...$base);
        }
        $r = $this->decrypt((array) ($e['resource'] ?? []));

        if (str_starts_with($type, 'REFUND.')) {
            $status = match ((string) ($r['refund_status'] ?? '')) {
                'SUCCESS' => 'refunded',
                'ABNORMAL', 'CLOSED' => 'refund_failed',
                default => '',
            };

            return new NotifyResult(...$base, tradeNo: (string) ($r['out_trade_no'] ?? ''), channelTradeNo: (string) ($r['transaction_id'] ?? ''),
                status: $status, currency: 'CNY', amount: PayAmount::fromMinor((string) ($r['amount']['refund'] ?? 0), 'CNY'),
                refundNo: (string) ($r['out_refund_no'] ?? ''), channelRefundNo: (string) ($r['refund_id'] ?? ''));
        }

        $currency = strtoupper((string) ($r['amount']['currency'] ?? 'CNY'));

        return new NotifyResult(...$base, tradeNo: (string) ($r['out_trade_no'] ?? ''), channelTradeNo: (string) ($r['transaction_id'] ?? ''),
            status: self::tradeStatus((string) ($r['trade_state'] ?? ''), false), currency: $currency,
            amount: PayAmount::fromMinor((string) ($r['amount']['total'] ?? 0), $currency));
    }

    /** 回调里的 resource 用 APIv3 密钥做 AEAD_AES_256_GCM 解密 */
    private function decrypt(array $resource): array
    {
        $cipher = base64_decode((string) ($resource['ciphertext'] ?? ''), true);
        $key = $this->cfg('api_v3_key');
        if ($cipher === false || strlen($cipher) <= 16 || strlen($key) !== 32) {
            throw new BusinessException('微信支付回调解密失败：请检查 APIv3 密钥是否为 32 位');
        }
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            (string) ($resource['nonce'] ?? ''), substr($cipher, -16), (string) ($resource['associated_data'] ?? ''));
        $data = $plain === false ? null : json_decode($plain, true);
        if (!is_array($data)) {
            throw new BusinessException('微信支付回调解密失败：请检查 APIv3 密钥是否正确');
        }

        return $data;
    }

    // ------------------------------------------------------------------ 查单
    public function confirm(string $tradeNo, string $channelTradeNo): QueryResult
    {
        return $this->query($tradeNo, $channelTradeNo);
    }

    public function query(string $tradeNo, string $channelTradeNo): QueryResult
    {
        [$status, $j] = $this->request('GET', '/v3/pay/transactions/out-trade-no/' . rawurlencode($tradeNo) . '?mchid=' . rawurlencode($this->cfg('mch_id')));
        if ($status === 404) {
            return new QueryResult('pending'); // 还没在微信侧生成订单
        }
        if ($status < 200 || $status >= 300) {
            $this->fail('查询微信支付', $j, $status);
        }
        $currency = strtoupper((string) ($j['amount']['currency'] ?? 'CNY'));

        return new QueryResult(self::tradeStatus((string) ($j['trade_state'] ?? ''), true), (string) ($j['transaction_id'] ?? ''), $currency,
            PayAmount::fromMinor((string) ($j['amount']['total'] ?? 0), $currency), ['trade_state' => $j['trade_state'] ?? null]);
    }

    /** 微信的交易状态 → 商城的支付状态。$forQuery 为 true 时未支付算 pending（查单用），回调里只关心终态 */
    private static function tradeStatus(string $state, bool $forQuery): string
    {
        return match ($state) {
            'SUCCESS', 'REFUND' => 'success', // REFUND：已支付后发生过退款，支付本身是成功的
            'CLOSED', 'REVOKED' => 'closed',
            'PAYERROR' => 'failed',
            default => $forQuery ? 'pending' : '',
        };
    }

    // ------------------------------------------------------------------ 退款
    public function refund(RefundRequest $req): RefundResult
    {
        $j = $this->call('POST', '/v3/refund/domestic/refunds', [
            'out_trade_no'  => $req->tradeNo,
            'out_refund_no' => $req->refundNo,
            'reason'        => mb_strcut($req->reason !== '' ? $req->reason : '订单退款', 0, 80),
            'notify_url'    => rtrim((string) config('app.url'), '/') . '/notify/pay/wechat',
            'amount'        => [
                'refund' => (int) PayAmount::toMinor($req->amount, 'CNY'), 'total' => (int) PayAmount::toMinor($req->totalAmount, 'CNY'), 'currency' => 'CNY',
            ],
        ], '微信支付退款');

        return self::refundResult($j);
    }

    public function queryRefund(string $refundNo, string $channelRefundNo): RefundResult
    {
        return self::refundResult($this->call('GET', '/v3/refund/domestic/refunds/' . rawurlencode($refundNo), null, '查询微信支付退款'));
    }

    private static function refundResult(array $j): RefundResult
    {
        $st = (string) ($j['status'] ?? '');

        return new RefundResult(success: $st === 'SUCCESS', channelRefundNo: (string) ($j['refund_id'] ?? ''), pending: $st === 'PROCESSING', raw: ['status' => $st]);
    }

    // ------------------------------------------------------------------ 连通性测试
    /** 查一个不存在的支付单：签名和商户号正确时微信返回“订单不存在”，否则返回签名错误 */
    public function ping(): array
    {
        [$status, $j] = $this->request('GET', '/v3/pay/transactions/out-trade-no/PING' . date('ymdHis') . random_int(1000, 9999) . '?mchid=' . rawurlencode($this->cfg('mch_id')));
        if ($status === 404 || ($status >= 200 && $status < 300)) {
            return ['ok' => true, 'mch_id' => $this->cfg('mch_id')];
        }
        $this->fail('连接微信支付', $j, $status);
    }

    // ------------------------------------------------------------------ 请求与签名
    private function call(string $method, string $path, ?array $body, string $what): array
    {
        [$status, $j] = $this->request($method, $path, $body);
        if ($status < 200 || $status >= 300) {
            $this->fail($what, $j, $status);
        }

        return $j;
    }

    /** @return array{0:int,1:array} HTTP 状态码、应答 JSON */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = time();
        $nonce = Str::random(32);
        $auth = sprintf('WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%d",serial_no="%s"',
            $this->cfg('mch_id'), $nonce, $this->sign("{$method}\n{$path}\n{$ts}\n{$nonce}\n{$json}\n"), $ts, $this->cfg('serial_no'));

        $headers = ['Authorization' => $auth, 'User-Agent' => 'gmmall/1.0'];
        if (($keyId = $this->cfg('public_key_id')) !== '') {
            $headers['Wechatpay-Serial'] = $keyId; // 告诉微信应答用哪把公钥签名
        }
        $req = $this->http()->withHeaders($headers);
        $res = $method === 'GET' ? $req->get($this->base() . $path) : $req->withBody($json, 'application/json')->post($this->base() . $path);

        return [$res->status(), (array) ($res->json() ?? [])];
    }

    /** 用商户 API 证书私钥签名（SHA256-RSA），返回 Base64 */
    private function sign(string $message): string
    {
        $key = openssl_pkey_get_private(self::pem($this->cfg('private_key')));
        if ($key === false || !openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new BusinessException('微信支付商户私钥无效，请检查配置（应为 apiclient_key.pem 的完整内容）');
        }

        return base64_encode($sig);
    }

    /** 粘贴进配置的 PEM 可能被压成一行或带多余空白，这里还原成标准格式 */
    private static function pem(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r", '\n'], "\n", $text));
        if (!preg_match('/^(-----BEGIN [A-Z ]+-----)(.+?)(-----END [A-Z ]+-----)$/s', $text, $m)) {
            return $text;
        }

        return $m[1] . "\n" . chunk_split(preg_replace('/\s+/', '', $m[2]), 64, "\n") . $m[3] . "\n";
    }
}
