<?php

namespace App\Services\Admin;

use App\Drivers\DriverManager;
use App\Drivers\DriverRegistry;
use App\Models\DriverConfig;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 接口驱动配置（gm_driver_config）：列表、保存、测试。当前接入 storage，短信/支付/物流后续复用同一套。 */
class DriverConfigService
{
    private const MASK = '******';

    public function __construct(private DriverManager $drivers) {}

    public function list(string $type): array
    {
        $rows = DriverConfig::query()->where('type', $type)->get()->keyBy('driver');
        $out = [];

        foreach (DriverRegistry::drivers($type) as $code => $def) {
            $row = $rows->get($code);
            $stored = $row?->config ?? [];

            $config = [];
            $merged = [];
            foreach ($def['fields'] as $key => $f) {
                $v = array_key_exists($key, $stored) ? $stored[$key] : $f['default'];
                $merged[$key] = $v;
                $config[$key] = self::isSecret($f) ? (($v !== '' && $v !== null) ? self::MASK : '') : $v;
            }

            $out[] = [
                'driver'     => $code,
                'name'       => $def['name'],
                'desc'       => $def['desc'],
                'fields'     => $this->fieldList($def['fields']),
                'is_enabled' => (int) ($row->is_enabled ?? 0),
                'is_default' => (int) ($row->is_default ?? 0),
                'sort'       => (int) ($row->sort ?? 0),
                'configured' => $this->isConfigured($def, $merged),
                'config'     => $config,
                'updated_at' => $row?->updated_at?->format('Y-m-d H:i:s'),
            ];
        }

        return $out;
    }

    public function save(string $type, string $driver, array $d): void
    {
        $def = DriverRegistry::driver($type, $driver);
        $row = DriverConfig::query()->where('type', $type)->where('driver', $driver)->first();
        $old = $row?->config ?? [];
        $input = (array) ($d['config'] ?? []);

        // 未提交的字段保持原值；密钥留空或原样回传（脱敏值）也保持原值
        $config = [];
        foreach ($def['fields'] as $key => $f) {
            $prev = array_key_exists($key, $old) ? $old[$key] : $f['default'];
            if (!array_key_exists($key, $input)) {
                $config[$key] = $prev;
                continue;
            }
            $v = $input[$key];
            $config[$key] = match ($f['type']) {
                'secret', 'secret_text' => ($v === null || $v === '' || $v === self::MASK) ? $prev : trim((string) $v),
                'switch' => (!empty($v) && $v !== '0') ? 1 : 0,
                default  => trim((string) ($v ?? '')),
            };
        }

        $enabled = (int) ($d['is_enabled'] ?? ($row->is_enabled ?? 0));
        $default = (int) ($d['is_default'] ?? ($row->is_default ?? 0));

        if ($enabled === 1 && !$this->isConfigured($def, $config)) {
            throw new BusinessException('请先填写完整的必填配置，再启用');
        }
        if ($default === 1 && $enabled !== 1) {
            throw new BusinessException('只有启用的驱动才能设为默认');
        }
        if ($row && (int) $row->is_default === 1 && ($enabled !== 1 || $default !== 1)) {
            throw new BusinessException('当前默认驱动不能直接禁用或取消默认，请先把其它驱动设为默认');
        }

        DB::transaction(function () use ($type, $driver, $def, $config, $enabled, $default, $d, $row) {
            if ($default === 1) {
                DriverConfig::query()->where('type', $type)->where('driver', '!=', $driver)->update(['is_default' => 0]);
            }
            DriverConfig::query()->updateOrCreate(
                ['type' => $type, 'driver' => $driver],
                [
                    'name'       => $def['name'],
                    'config'     => $config,
                    'is_enabled' => $enabled,
                    'is_default' => $default,
                    'sort'       => (int) ($d['sort'] ?? ($row->sort ?? 0)),
                ]
            );
        });
    }

    /** 存储类型的连通性测试：写入一个小文件、确认存在、再删除。使用已保存的配置。 */
    public function test(string $type, string $driver): array
    {
        if (!in_array($type, ['storage', 'translate', 'pay', 'sms', 'express'], true)) {
            throw new BusinessException('该类型暂不支持测试');
        }
        $def = DriverRegistry::driver($type, $driver);
        $row = DriverConfig::query()->where('type', $type)->where('driver', $driver)->first();
        $config = $row?->config ?? [];

        $merged = [];
        foreach ($def['fields'] as $key => $f) {
            $merged[$key] = array_key_exists($key, $config) ? $config[$key] : $f['default'];
        }
        if (!$this->isConfigured($def, $merged)) {
            throw new BusinessException('请先保存完整的配置，再测试');
        }

        if ($type === 'translate') {
            return $this->testTranslate($driver, $merged);
        }
        if ($type === 'pay') {
            return $this->testPay($driver, $merged);
        }
        if ($type === 'sms') {
            return $this->testSms($driver, $merged);
        }
        if ($type === 'express') {
            try {
                return $this->drivers->expressFromConfig($driver, $merged)->ping();
            } catch (BusinessException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new BusinessException('测试失败：' . Str::limit($e->getMessage(), 300));
            }
        }

        $drv = $this->drivers->storageFromConfig($driver, $merged);
        $path = '_test/' . Str::random(16) . '.txt';

        try {
            $drv->put($path, 'gm-mall storage test');
            $ok = $drv->exists($path);
            $sample = $drv->url($path);
            $drv->delete($path);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BusinessException('测试失败：' . Str::limit($e->getMessage(), 300));
        }
        if (!$ok) {
            throw new BusinessException('测试失败：文件写入后未能读取到');
        }

        return ['url_sample' => $sample];
    }

    /** 支付渠道连通性测试：用保存的密钥调一个只读接口（Stripe 查余额 / PayPal 取令牌），不会产生交易 */
    private function testPay(string $driver, array $config): array
    {
        try {
            return $this->drivers->payFromConfig($driver, $config)->ping();
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BusinessException('测试失败：' . Str::limit($e->getMessage(), 300));
        }
    }

    /** 短信平台连通性测试：查询余额，验证接口地址、密钥、IP 白名单都可用，不会发短信 */
    private function testSms(string $driver, array $config): array
    {
        try {
            return $this->drivers->smsFromConfig($driver, $config)->ping();
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BusinessException('测试失败：' . Str::limit($e->getMessage(), 300));
        }
    }

    /** 翻译模型连通性测试：发一句简单的话，确认接口地址、密钥、模型名都可用 */
    private function testTranslate(string $driver, array $config): array
    {
        $drv = $this->drivers->translateFromConfig($driver, $config);
        try {
            $reply = $drv->complete('You are a translator. Translate the user text into English. Reply with the translation only.', '你好，欢迎光临', false);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BusinessException('测试失败：' . Str::limit($e->getMessage(), 300));
        }

        return ['reply' => Str::limit(trim($reply), 200), 'model' => $drv->model()];
    }

    private function isConfigured(array $def, array $config): bool
    {
        foreach ($def['fields'] as $key => $f) {
            if (!empty($f['required']) && trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** 密钥类字段（secret 单行、secret_text 多行，如 PEM 私钥）：列表里不回显，留空或原样提交表示不修改 */
    private static function isSecret(array $field): bool
    {
        return in_array($field['type'], ['secret', 'secret_text'], true);
    }

    private function fieldList(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $f) {
            $out[] = ['key' => $key] + $f;
        }

        return $out;
    }
}
