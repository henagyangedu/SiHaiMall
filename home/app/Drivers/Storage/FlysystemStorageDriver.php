<?php

namespace App\Drivers\Storage;

use App\Drivers\Contracts\StorageDriver;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * 基于 Laravel 文件系统的存储驱动。
 * local 写入 public/uploads；其余（阿里云、腾讯云、GCS、通用 S3）都走 S3 兼容协议，差别只在配置。
 */
class FlysystemStorageDriver implements StorageDriver
{
    private ?Filesystem $disk = null;

    public function __construct(private string $code, private array $config = []) {}

    public function code(): string
    {
        return $this->code;
    }

    public function putFile(string $path, UploadedFile $file): void
    {
        $dir = dirname($path);
        $this->disk()->putFileAs($dir === '.' ? '' : $dir, $file, basename($path));
    }

    public function put(string $path, string $contents): void
    {
        $this->disk()->put($path, $contents);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    public function url(string $path): string
    {
        $path = ltrim($path, '/');

        if ($this->code === 'local') {
            return rtrim((string) config('app.url'), '/') . '/uploads/' . $path;
        }

        $domain = trim((string) ($this->config['domain'] ?? ''));
        if ($domain !== '') {
            return rtrim($domain, '/') . '/' . $path;
        }

        $endpoint = rtrim((string) ($this->config['endpoint'] ?? ''), '/');
        $bucket = (string) ($this->config['bucket'] ?? '');
        if (!empty($this->config['path_style'])) {
            return "{$endpoint}/{$bucket}/{$path}";
        }

        $p = parse_url($endpoint);

        return ($p['scheme'] ?? 'https') . '://' . $bucket . '.' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . '/' . $path;
    }

    private function disk(): Filesystem
    {
        if ($this->disk) {
            return $this->disk;
        }

        if ($this->code === 'local') {
            $cfg = ['driver' => 'local', 'root' => public_path('uploads'), 'throw' => true];
        } else {
            if (!class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
                throw new BusinessException('缺少 S3 依赖，请先在后端项目目录执行：composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies');
            }
            $cfg = [
                'driver'                  => 's3',
                'key'                     => (string) ($this->config['access_key'] ?? ''),
                'secret'                  => (string) ($this->config['secret_key'] ?? ''),
                'region'                  => (string) (($this->config['region'] ?? '') ?: 'auto'),
                'bucket'                  => (string) ($this->config['bucket'] ?? ''),
                'endpoint'                => (string) ($this->config['endpoint'] ?? ''),
                'use_path_style_endpoint' => !empty($this->config['path_style']),
                'throw'                   => true,
            ];
        }

        return $this->disk = Storage::build($cfg);
    }
}
