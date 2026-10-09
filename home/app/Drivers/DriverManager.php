<?php

namespace App\Drivers;

use App\Drivers\Contracts\ExpressDriver;
use App\Drivers\Contracts\PayDriver;
use App\Drivers\Contracts\SmsDriver;
use App\Drivers\Contracts\StorageDriver;
use App\Drivers\Contracts\TranslateDriver;
use App\Drivers\Express\Kuaidi100Driver;
use App\Drivers\Sms\GmSmsDriver;
use App\Drivers\Storage\FlysystemStorageDriver;
use App\Drivers\Translate\ClaudeDriver;
use App\Drivers\Translate\OpenAiCompatibleDriver;
use App\Models\DriverConfig;
use App\Support\Exceptions\BusinessException;

/**
 * 驱动工厂：按 gm_driver_config 中启用的配置创建驱动实例。
 * 新增驱动 = 写一个实现类 + 在 DriverRegistry 登记配置项 + 在这里接入。
 */
class DriverManager
{
    /** 支付驱动登记表：驱动编码 => 实现类（接入时逐个补充） */
    private const PAY_MAP = [
        'alipay' => \App\Drivers\Pay\AlipayDriver::class,
        'wechat' => \App\Drivers\Pay\WechatDriver::class,
        'paypal' => \App\Drivers\Pay\PaypalDriver::class,
        'stripe' => \App\Drivers\Pay\StripeDriver::class,
    ];

    /** 已启用的支付驱动（创建支付用） */
    public function pay(string $driver): PayDriver
    {
        $cfg = DriverConfig::enabled('pay', $driver);

        return $this->payFromConfig($driver, $cfg->config ?? []);
    }

    /**
     * 回调、补单、重新处理用：只要保存过配置就可用，不要求仍处于「启用」状态。
     * 否则后台临时关闭某渠道后，用户之前已发起的付款回调会被拒收，造成「钱收了订单没更新」。
     */
    public function payForNotify(string $driver): PayDriver
    {
        $row = DriverConfig::query()->where('type', 'pay')->where('driver', $driver)->first()
            ?? throw new BusinessException("支付渠道未配置：{$driver}");

        return $this->payFromConfig($driver, $row->config ?? []);
    }

    public function payFromConfig(string $driver, array $config): PayDriver
    {
        $class = self::PAY_MAP[$driver] ?? throw new BusinessException("不支持的支付驱动：{$driver}");
        DriverRegistry::driver('pay', $driver);

        return new $class($config);
    }

    /** 当前默认存储驱动；后台还没有指定时回退到本地存储，保证开箱可用 */
    public function storage(): StorageDriver
    {
        $row = DriverConfig::query()
            ->where('type', 'storage')
            ->where('is_enabled', 1)
            ->where('is_default', 1)
            ->first();

        if (!$row) {
            return new FlysystemStorageDriver('local', []);
        }

        return $this->storageFromConfig($row->driver, $row->config ?? []);
    }

    /** 按指定配置创建存储驱动（后台“测试连接”用） */
    public function storageFromConfig(string $driver, array $config): StorageDriver
    {
        DriverRegistry::driver('storage', $driver); // 校验驱动编码

        return new FlysystemStorageDriver($driver, $config);
    }

    /** 已启用的 AI 翻译驱动（openai / deepseek / qwen 走 OpenAI 兼容接口，claude 走 Anthropic 的 Messages 接口） */
    public function translate(string $driver): TranslateDriver
    {
        $row = DriverConfig::enabled('translate', $driver);

        return $this->translateFromConfig($driver, $row->config ?? []);
    }

    /** 按指定配置创建翻译驱动（后台“测试连接”用） */
    public function translateFromConfig(string $driver, array $config): TranslateDriver
    {
        DriverRegistry::driver('translate', $driver); // 校验驱动编码

        return $driver === 'claude' ? new ClaudeDriver($config) : new OpenAiCompatibleDriver($driver, $config);
    }

    /** 已启用的短信驱动（有多个时取默认、排序靠前的） */
    public function sms(): SmsDriver
    {
        $row = DriverConfig::enabled('sms');

        return $this->smsFromConfig($row->driver, $row->config ?? []);
    }

    /** 按指定配置创建短信驱动（后台“测试连接”用） */
    public function smsFromConfig(string $driver, array $config): SmsDriver
    {
        $def = DriverRegistry::driver('sms', $driver); // 校验驱动编码
        // 没保存过的字段用登记表里的默认值（如接口地址、场景标识）
        foreach ($def['fields'] as $key => $f) {
            $config[$key] ??= $f['default'];
        }

        return new GmSmsDriver($config);
    }

    /** 已启用的物流查询驱动 */
    public function express(): ExpressDriver
    {
        $row = DriverConfig::enabled('express');

        return $this->expressFromConfig($row->driver, $row->config ?? []);
    }

    /** 按指定配置创建物流查询驱动（后台“测试连接”用） */
    public function expressFromConfig(string $driver, array $config): ExpressDriver
    {
        $def = DriverRegistry::driver('express', $driver); // 校验驱动编码
        foreach ($def['fields'] as $key => $f) {
            $config[$key] ??= $f['default'];
        }

        return new Kuaidi100Driver($config);
    }

    // 后续按同样方式补充：waybill()
}
