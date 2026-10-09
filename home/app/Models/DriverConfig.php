<?php

namespace App\Models;

use App\Support\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;

/**
 * 第三方接口驱动配置（表 gm_driver_config，前缀由 database.php 的 prefix=gm_ 提供）
 * config 字段使用加密 cast，读写自动加解密；后台输出时必须脱敏。
 */
class DriverConfig extends Model
{
    protected $table = 'driver_config';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config'     => 'encrypted:array',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** 取某类型下指定驱动的启用配置；$driver 为空则取默认驱动 */
    public static function enabled(string $type, ?string $driver = null): self
    {
        $q = static::query()->where('type', $type)->where('is_enabled', 1);
        $q = $driver ? $q->where('driver', $driver) : $q->orderByDesc('is_default')->orderBy('sort');
        return $q->first() ?? throw new BusinessException("接口未启用：{$type}" . ($driver ? "/{$driver}" : ''));
    }
}
