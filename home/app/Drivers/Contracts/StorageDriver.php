<?php

namespace App\Drivers\Contracts;

use Illuminate\Http\UploadedFile;

/** 存储驱动统一接口：本地、阿里云 OSS、腾讯云 COS、Google Cloud Storage、S3 兼容 都实现它 */
interface StorageDriver
{
    /** 驱动编码：local / aliyun / tencent / gcs / s3 */
    public function code(): string;

    /** 上传请求中的文件，$path 为存储桶内的相对路径，如 goods/2026/10/09/xxx.jpg */
    public function putFile(string $path, UploadedFile $file): void;

    public function put(string $path, string $contents): void;

    public function exists(string $path): bool;

    public function delete(string $path): void;

    /** 对外访问地址（完整 URL） */
    public function url(string $path): string;
}
