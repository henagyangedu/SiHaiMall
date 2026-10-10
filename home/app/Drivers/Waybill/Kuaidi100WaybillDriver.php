<?php

namespace App\Drivers\Waybill;

use App\Drivers\Contracts\WaybillDriver;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 快递100 电子面单下单接口（api.kuaidi100.com/label/order，method=order）。
 * 鉴权：表单参数 key、t（毫秒时间戳）、param（JSON）、sign = 大写 MD5(param + t + key + secret)。
 * printType 用 IMAGE：返回一张面单图片的地址，后台在浏览器里打开打印，不走快递100 的云打印机。
 *
 * 注意：这个驱动是按快递100 的公开文档写的，没有用真实账号联调过。各家快递要求的字段不完全一样
 * （是否要网点、是否要密码、模板编号怎么填），第一次接真实账号时请对照快递100 后台的“电子面单”说明核对。
 */
class Kuaidi100WaybillDriver implements WaybillDriver
{
    /** 商城快递编码 → 快递100 的公司编码（只列国内能取电子面单的） */
    public const COMPANY = [
        'SF' => 'shunfeng', 'ZTO' => 'zhongtong', 'YTO' => 'yuantong', 'STO' => 'shentong', 'YUNDA' => 'yunda',
        'JTEXPRESS' => 'jtexpress', 'EMS' => 'ems',
    ];

    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'kuaidi100';
    }

    public function supports(string $expressCode): bool
    {
        return isset(self::COMPANY[strtoupper($expressCode)]);
    }

    public function create(array $order): array
    {
        $com = self::COMPANY[strtoupper((string) $order['express_code'])] ?? throw new BusinessException('这家快递公司暂不支持电子面单');
        $sender = ['name' => trim((string) ($this->config['sender_name'] ?? '')), 'mobile' => trim((string) ($this->config['sender_mobile'] ?? '')), 'printAddr' => trim((string) ($this->config['sender_address'] ?? ''))];
        if (in_array('', $sender, true)) {
            throw new BusinessException('电子面单的发货人信息还没有填完整');
        }
        $account = $order['account'];

        $data = $this->request('order', [
            'printType' => 'IMAGE',
            'kuaidicom' => $com,
            'partnerId' => (string) $account['partner_id'], 'partnerKey' => (string) $account['partner_key'], 'net' => (string) $account['net'],
            'tempId' => (string) $account['temp_id'],
            'recMan' => ['name' => (string) $order['receiver']['name'], 'mobile' => (string) $order['receiver']['mobile'], 'printAddr' => (string) $order['receiver']['address']],
            'sendMan' => $sender,
            'cargo' => mb_substr((string) $order['cargo'], 0, 60),
            'count' => 1,
            'weight' => (string) $order['weight'],
            'payType' => 'MONTHLY',
            'expType' => '标准快递',
            'remark' => mb_substr((string) $order['remark'], 0, 100),
            // 商城订单号：方便在快递100 后台对账
            'orderId' => (string) $order['order_no'],
        ]);

        // 成功：success=true、code=200，data 里有 kuaidinum（快递单号）、taskId、label（面单图片地址）
        $ok = ($data['success'] ?? false) === true || (string) ($data['code'] ?? '') === '200';
        $result = (array) ($data['data'] ?? []);
        $no = trim((string) ($result['kuaidinum'] ?? ''));
        if (!$ok || $no === '') {
            $msg = mb_substr((string) ($data['message'] ?? '快递100 返回了无法识别的内容'), 0, 200);
            Log::warning('快递100 电子面单取号失败', ['com' => $com, 'code' => (string) ($data['code'] ?? ''), 'msg' => $msg, 'order_no' => (string) $order['order_no']]);
            throw new BusinessException('取号失败：' . $msg . (isset($data['code']) ? '（' . $data['code'] . '）' : ''));
        }

        return ['express_no' => $no, 'task_id' => (string) ($result['taskId'] ?? ''), 'label_url' => mb_substr((string) ($result['label'] ?? ''), 0, 500)];
    }

    /** @param array<string,mixed> $param */
    private function request(string $method, array $param): array
    {
        $key = trim((string) ($this->config['key'] ?? ''));
        $secret = trim((string) ($this->config['secret'] ?? ''));
        if ($key === '' || $secret === '') {
            throw new BusinessException('电子面单还没有配置完整');
        }
        $json = json_encode($param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $t = (string) (int) (microtime(true) * 1000);
        $http = Http::asForm()->timeout(max((int) ($this->config['timeout'] ?? 15), 3));
        if (($proxy = trim((string) ($this->config['proxy'] ?? ''))) !== '') {
            $http = $http->withOptions(['proxy' => $proxy]);
        }
        try {
            $res = $http->post(rtrim((string) ($this->config['base_url'] ?? 'https://api.kuaidi100.com'), '/') . '/label/order', [
                'method' => $method, 'key' => $key, 't' => $t, 'param' => $json, 'sign' => self::sign($json, $t, $key, $secret),
            ]);
        } catch (\Throwable $e) {
            // 超时的情况下面单可能已经生成：把订单号记下来，方便到快递100 后台核对
            Log::warning('快递100 电子面单请求失败', ['error' => mb_substr($e->getMessage(), 0, 300), 'order_no' => (string) ($param['orderId'] ?? '')]);
            throw new BusinessException('电子面单服务暂时连不上，请稍后再试；如果反复出现，请到快递100 后台确认这张订单有没有已经生成面单');
        }
        $data = $res->json();
        if (!is_array($data)) {
            Log::warning('快递100 电子面单返回了非 JSON 内容', ['http' => $res->status()]);
            throw new BusinessException('电子面单服务暂时不可用，请稍后再试');
        }

        return $data;
    }

    public static function sign(string $paramJson, string $t, string $key, string $secret): string
    {
        return strtoupper(md5($paramJson . $t . $key . $secret));
    }
}
