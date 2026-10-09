<?php

namespace App\Drivers\Express;

use App\Drivers\Contracts\ExpressDriver;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 快递100 实时查询接口（poll.kuaidi100.com/poll/query.do）。
 * 鉴权：表单参数 customer、param（JSON）、sign = 大写 MD5(param + key + customer)。
 * 按次计费，所以缓存和查询频率由 Common\ExpressTrackService 控制。
 */
class Kuaidi100Driver implements ExpressDriver
{
    /** 商城快递编码 → 快递100 的公司编码 */
    private const COMPANY = [
        'SF' => 'shunfeng', 'ZTO' => 'zhongtong', 'YTO' => 'yuantong', 'STO' => 'shentong', 'YUNDA' => 'yunda',
        'JTEXPRESS' => 'jtexpress', 'EMS' => 'ems', 'DHL' => 'dhl', 'FEDEX' => 'fedex', 'UPS' => 'ups', 'USPS' => 'usps',
    ];

    /** 快递100 的 state：3 签收；2 疑难、4 退签、6 退回、13 清关异常、14 拒签 算异常；其余（在途、揽收、派件、清关中等）算运输中 */
    private const SIGNED = ['3'];

    private const ABNORMAL = ['2', '4', '6', '13', '14'];

    /** 说明“暂时查不到”的返回码：单号还没有轨迹，不算故障 */
    private const NO_RESULT = ['500'];

    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'kuaidi100';
    }

    public function supports(string $expressCode): bool
    {
        return isset(self::COMPANY[strtoupper($expressCode)]);
    }

    public function query(string $expressCode, string $expressNo, string $phone = ''): array
    {
        $com = self::COMPANY[strtoupper($expressCode)] ?? throw new BusinessException('这家快递公司暂不支持在线查询');
        $data = $this->request(['com' => $com, 'num' => trim($expressNo), 'phone' => $phone, 'resultv2' => '1']);

        // 成功时返回 message=ok 和 data；失败时返回 result=false、returnCode、message
        if (($data['message'] ?? '') !== 'ok' || !isset($data['data'])) {
            $code = (string) ($data['returnCode'] ?? '');
            if (in_array($code, self::NO_RESULT, true)) {
                return ['status' => 0, 'traces' => []];
            }
            Log::warning('快递100 查询失败', ['com' => $com, 'code' => $code, 'msg' => mb_substr((string) ($data['message'] ?? ''), 0, 200)]);
            throw new BusinessException('物流查询服务暂时不可用，请稍后再试');
        }

        $traces = [];
        foreach ((array) $data['data'] as $row) {
            $context = trim((string) ($row['context'] ?? ''));
            if ($context !== '') {
                $traces[] = ['time' => (string) ($row['ftime'] ?? $row['time'] ?? ''), 'context' => mb_substr($context, 0, 500)];
            }
        }
        $state = (string) ($data['state'] ?? '');
        $status = match (true) {
            in_array($state, self::SIGNED, true) => 2,
            in_array($state, self::ABNORMAL, true) => 3,
            $traces !== [] => 1,
            default => 0,
        };

        return ['status' => $status, 'traces' => $traces];
    }

    /** 用一个不存在的单号查一次：账号或密钥不对会返回鉴权错误，正确时返回“查询无结果” */
    public function ping(): array
    {
        $data = $this->request(['com' => 'shunfeng', 'num' => 'SF0000000000000', 'phone' => '', 'resultv2' => '1']);
        $code = (string) ($data['returnCode'] ?? '');
        if (($data['message'] ?? '') === 'ok' || in_array($code, self::NO_RESULT, true)) {
            return ['ok' => true];
        }
        throw new BusinessException('测试失败：' . mb_substr((string) ($data['message'] ?? '快递100 返回了无法识别的内容'), 0, 200) . ($code !== '' ? "（{$code}）" : ''));
    }

    /** @param array<string,string> $param */
    private function request(array $param): array
    {
        $customer = trim((string) ($this->config['customer'] ?? ''));
        $key = trim((string) ($this->config['key'] ?? ''));
        if ($customer === '' || $key === '') {
            throw new BusinessException('物流查询还没有配置完整');
        }
        $json = json_encode($param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $http = Http::asForm()->timeout(max((int) ($this->config['timeout'] ?? 10), 3));
        if (($proxy = trim((string) ($this->config['proxy'] ?? ''))) !== '') {
            $http = $http->withOptions(['proxy' => $proxy]);
        }
        try {
            $res = $http->post(rtrim((string) ($this->config['base_url'] ?? 'https://poll.kuaidi100.com'), '/') . '/poll/query.do', [
                'customer' => $customer, 'param' => $json, 'sign' => self::sign($json, $key, $customer),
            ]);
        } catch (\Throwable $e) {
            Log::warning('快递100 请求失败', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            throw new BusinessException('物流查询服务暂时不可用，请稍后再试');
        }
        $data = $res->json();
        if (!is_array($data)) {
            Log::warning('快递100 返回了非 JSON 内容', ['http' => $res->status()]);
            throw new BusinessException('物流查询服务暂时不可用，请稍后再试');
        }

        return $data;
    }

    public static function sign(string $paramJson, string $key, string $customer): string
    {
        return strtoupper(md5($paramJson . $key . $customer));
    }
}
