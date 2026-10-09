<?php

namespace App\Services\Common;

use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 系统设置（gm_config 键值表）。
 * 配置项在 DEFS 中集中定义：类型、是否多语言、默认值、校验范围。新增配置项只改这里和对应前端页面。
 * 多语言项存 JSON：{"zh-CN":"...","en":"..."}。
 */
class ConfigService
{
    public const DEFS = [
        'base' => [
            'site_name'     => ['label' => '站点名称', 'type' => 'string', 'lang' => true, 'max' => 100],
            'site_logo'     => ['label' => '站点Logo', 'type' => 'string', 'max' => 255],
            'site_favicon'  => ['label' => '站点图标', 'type' => 'string', 'max' => 255],
            'site_desc'     => ['label' => '站点描述', 'type' => 'text', 'lang' => true, 'max' => 500],
            'site_keywords' => ['label' => '站点关键词', 'type' => 'string', 'lang' => true, 'max' => 255],
            'company_name'  => ['label' => '公司名称', 'type' => 'string', 'max' => 100],
            'service_email' => ['label' => '客服邮箱', 'type' => 'string', 'max' => 100, 'email' => true],
            'service_phone' => ['label' => '客服电话', 'type' => 'string', 'max' => 30],
            'copyright'     => ['label' => '版权信息', 'type' => 'string', 'lang' => true, 'max' => 255],
            'site_icp'      => ['label' => '备案号', 'type' => 'string', 'max' => 100],
            'site_closed'   => ['label' => '关闭站点', 'type' => 'bool', 'default' => 0],
            'close_notice'  => ['label' => '关闭提示', 'type' => 'text', 'lang' => true, 'max' => 500],
            'email_verify_enabled' => ['label' => '邮箱注册需要验证码', 'type' => 'bool', 'default' => 0],
        ],
        'trade' => [
            'order_expire_minutes' => ['label' => '未支付订单自动关闭（分钟）', 'type' => 'int', 'min' => 1, 'max' => 10080, 'default' => 30],
            'auto_receive_days'    => ['label' => '自动确认收货（天）', 'type' => 'int', 'min' => 1, 'max' => 90, 'default' => 7],
            'after_sale_days'      => ['label' => '售后申请期限（天）', 'type' => 'int', 'min' => 0, 'max' => 90, 'default' => 7],
            'stock_deduct_mode'    => ['label' => '库存扣减时机', 'type' => 'enum', 'options' => ['order', 'pay'], 'default' => 'order'],
            'min_order_amount'     => ['label' => '最低下单金额', 'type' => 'decimal', 'min' => 0, 'default' => '0'],
            'points_deduct_enabled' => ['label' => '下单积分抵扣', 'type' => 'bool', 'default' => 0],
            'points_per_unit'      => ['label' => '多少积分抵 1 元（基准币种）', 'type' => 'int', 'min' => 1, 'max' => 1000000, 'default' => 100],
            'points_max_percent'   => ['label' => '积分最多抵扣商品金额的百分比', 'type' => 'int', 'min' => 1, 'max' => 99, 'default' => 50],
            'points_earn_enabled'  => ['label' => '消费得积分', 'type' => 'bool', 'default' => 0],
            'points_earn_per_unit' => ['label' => '每消费 1 元（基准币种）赠送多少积分', 'type' => 'int', 'min' => 1, 'max' => 10000, 'default' => 1],
        ],
        // 协议与政策：多语言富文本。键名同时是 C 端接口 /api/protocols/{key} 的 key
        'protocol' => [
            'user_agreement'         => ['label' => '用户协议', 'type' => 'text', 'lang' => true, 'max' => 30000],
            'privacy_policy'         => ['label' => '隐私政策', 'type' => 'text', 'lang' => true, 'max' => 30000],
            'refund_policy'          => ['label' => '退换货政策', 'type' => 'text', 'lang' => true, 'max' => 30000],
            'shipping_policy'        => ['label' => '配送政策', 'type' => 'text', 'lang' => true, 'max' => 30000],
            'distribution_agreement' => ['label' => '分销员协议', 'type' => 'text', 'lang' => true, 'max' => 30000],
        ],
        'distribution' => [
            'enabled'      => ['label' => '开启分销', 'type' => 'bool', 'default' => 0],
            'level'        => ['label' => '分销层级', 'type' => 'int', 'min' => 1, 'max' => 2, 'default' => 1],
            'rate_1'       => ['label' => '一级佣金比例（%）', 'type' => 'decimal', 'min' => 0, 'max' => 50, 'default' => '10'],
            'rate_2'       => ['label' => '二级佣金比例（%）', 'type' => 'decimal', 'min' => 0, 'max' => 50, 'default' => '5'],
            'min_withdraw' => ['label' => '最低提现金额', 'type' => 'decimal', 'min' => 0, 'default' => '100'],
        ],
    ];

    /** 单个配置值编码后的最大字节数（gm_config.value 为 TEXT） */
    private const MAX_VALUE_BYTES = 60000;

    /** 可公开给 C 端的配置项 */
    public const PUBLIC_KEYS = [
        'base' => ['site_name', 'site_logo', 'site_favicon', 'site_desc', 'site_keywords', 'company_name',
                   'service_email', 'service_phone', 'copyright', 'site_icp', 'site_closed', 'close_notice'],
    ];

    public function __construct(private LocaleService $locale) {}

    public function defs(string $group): array
    {
        return self::DEFS[$group] ?? throw new BusinessException('未知的配置分组');
    }

    /** 取整组配置（多语言项为 {code: text}） */
    public function get(string $group): array
    {
        $defs = $this->defs($group);
        $raw = $this->raw($group);
        $out = [];
        foreach ($defs as $key => $def) {
            $out[$key] = $this->decode($def, array_key_exists($key, $raw) ? $raw[$key] : null);
        }

        return $out;
    }

    /** 取单个配置；多语言项传 $lang 则返回该语言文本（缺失时回退默认语言） */
    public function value(string $group, string $key, ?string $lang = null): mixed
    {
        $all = $this->get($group);
        $def = $this->defs($group)[$key] ?? throw new BusinessException('未知的配置项');
        $v = $all[$key];

        if (!empty($def['lang']) && $lang !== null) {
            return $v[$lang] ?? $v[$this->locale->defaultLang()] ?? '';
        }

        return $v;
    }

    /** C 端可见的站点配置，多语言项已按语言解析成文本 */
    public function publicBase(string $lang): array
    {
        $all = $this->get('base');
        $defs = $this->defs('base');
        $default = $this->locale->defaultLang();
        $out = [];
        foreach (self::PUBLIC_KEYS['base'] as $key) {
            $v = $all[$key];
            $out[$key] = !empty($defs[$key]['lang']) ? ($v[$lang] ?? $v[$default] ?? '') : $v;
        }

        return $out;
    }

    public function save(string $group, array $data): void
    {
        $defs = $this->defs($group);

        // gm_config.value 是 TEXT（约 64KB），所有语言的内容存在同一行里；超出时明确报错，避免被数据库截断或拒绝
        $encoded = [];
        foreach ($defs as $key => $def) {
            if (array_key_exists($key, $data)) {
                $encoded[$key] = $this->encode($def, $data[$key]);
                if (strlen($encoded[$key]) > self::MAX_VALUE_BYTES) {
                    throw new BusinessException("「{$def['label']}」各语言的内容合计过长，请精简后再保存");
                }
            }
        }

        DB::transaction(function () use ($group, $defs, $data, $encoded) {
            foreach ($defs as $key => $def) {
                if (!array_key_exists($key, $data)) {
                    continue;
                }
                DB::table('config')->updateOrInsert(
                    ['group' => $group, 'key' => $key],
                    [
                        'value'      => $encoded[$key],
                        'is_lang'    => !empty($def['lang']) ? 1 : 0,
                        'remark'     => $def['label'],
                        'updated_at' => now(),
                    ]
                );
            }
        });

        Cache::forget("mall:config:{$group}");
    }

    /** 供 FormRequest 使用的校验规则 */
    public function rules(string $group): array
    {
        $rules = [];
        foreach ($this->defs($group) as $key => $def) {
            $max = $def['max'] ?? 255;
            if (!empty($def['lang'])) {
                $rules[$key] = ['sometimes', 'nullable', 'array'];
                $rules["{$key}.*"] = ['nullable', 'string', "max:{$max}"];
                continue;
            }
            $rules[$key] = match ($def['type']) {
                'bool'    => ['sometimes', 'in:0,1'],
                'int'     => ['sometimes', 'integer', 'min:' . ($def['min'] ?? 0), 'max:' . ($def['max'] ?? 2147483647)],
                'decimal' => ['sometimes', 'numeric', 'min:' . ($def['min'] ?? 0), 'max:' . ($def['max'] ?? 9999999999)],
                'enum'    => ['sometimes', 'in:' . implode(',', $def['options'])],
                default   => array_filter(['sometimes', 'nullable', 'string', "max:{$max}", !empty($def['email']) ? 'email' : null]),
            };
        }

        return $rules;
    }

    public function attributes(string $group): array
    {
        $out = [];
        foreach ($this->defs($group) as $key => $def) {
            $out[$key] = $def['label'];
            $out["{$key}.*"] = $def['label'];
        }

        return $out;
    }

    private function raw(string $group): array
    {
        return Cache::remember("mall:config:{$group}", 3600, fn () => DB::table('config')->where('group', $group)->pluck('value', 'key')->all());
    }

    private function decode(array $def, mixed $raw): mixed
    {
        if (!empty($def['lang'])) {
            $arr = $raw === null ? [] : json_decode((string) $raw, true);

            return is_array($arr) ? $arr : [];
        }
        if ($raw === null) {
            return $def['default'] ?? (in_array($def['type'], ['bool', 'int'], true) ? 0 : '');
        }

        return in_array($def['type'], ['bool', 'int'], true) ? (int) $raw : (string) $raw;
    }

    private function encode(array $def, mixed $val): string
    {
        if (!empty($def['lang'])) {
            $out = [];
            foreach ($this->locale->codes() as $code) {
                $out[$code] = (string) (is_array($val) ? ($val[$code] ?? '') : '');
            }

            return json_encode($out, JSON_UNESCAPED_UNICODE);
        }

        return match ($def['type']) {
            'bool'  => ((int) $val) === 1 ? '1' : '0',
            'int'   => (string) (int) $val,
            default => (string) ($val ?? ''),
        };
    }
}
