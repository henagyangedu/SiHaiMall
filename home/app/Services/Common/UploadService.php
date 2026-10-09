<?php

namespace App\Services\Common;

use App\Drivers\Contracts\StorageDriver;
use App\Drivers\DriverManager;
use App\Models\UploadFile;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 文件上传：写入当前默认存储驱动，并记录到 gm_file。目前支持图片，其它文件类型后续按需加入。
 *
 * 买家上传（售后凭证、头像）统一放在 member/{会员ID}/{用途}/ 目录下：gm_file 没有会员字段，靠路径区分归属；
 * 业务里收到买家提交的图片地址时用 assertMemberImages 校验，只接受他本人上传的图片。
 */
class UploadService
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'];

    /** 买家可上传的图片格式、用途，以及每人每天的上传张数上限 */
    private const MEMBER_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

    public const MEMBER_SCENES = ['refund', 'avatar'];

    private const MEMBER_DAILY_LIMIT = 60;

    public function __construct(private DriverManager $drivers) {}

    public function image(UploadedFile $file, string $scene, int $adminId): array
    {
        return $this->store($file, $scene, $scene, self::IMAGE_EXT, $adminId);
    }

    /** 买家上传图片。返回 {id,url,name,size,width,height}，业务表里保存 url */
    public function memberImage(UploadedFile $file, string $scene, int $memberId): array
    {
        if (!in_array($scene, self::MEMBER_SCENES, true)) {
            throw new BusinessException('不支持的图片用途');
        }
        $key = "member_upload:{$memberId}:" . date('Ymd');
        if ((int) Cache::get($key, 0) >= self::MEMBER_DAILY_LIMIT) {
            throw new BusinessException('今天上传的图片太多了，请明天再试');
        }

        $data = $this->store($file, $scene, self::memberDir($memberId) . $scene, self::MEMBER_IMAGE_EXT, 0);
        Cache::add($key, 0, 86400);
        Cache::increment($key);

        return $data;
    }

    /**
     * 校验买家提交的图片地址：必须是他本人通过上传接口传的图片，否则抛 BusinessException。
     * @param string[] $urls
     */
    public function assertMemberImages(int $memberId, array $urls): void
    {
        if ($urls === []) {
            return;
        }
        $driver = $this->drivers->storage();
        $dir = self::memberDir($memberId);
        $paths = [];
        foreach ($urls as $url) {
            $pos = is_string($url) ? strpos($url, $dir) : false;
            $path = $pos === false ? '' : substr($url, $pos);
            // 地址必须和存储驱动给出的地址完全一致，防止拿别的域名、带参数或带 ../ 的地址冒充
            if ($path === '' || str_contains($path, '..') || $driver->url($path) !== $url) {
                throw new BusinessException('图片无效，请重新上传');
            }
            $paths[] = $path;
        }
        $paths = array_values(array_unique($paths));
        if (UploadFile::query()->whereIn('path', $paths)->count() !== count($paths)) {
            throw new BusinessException('图片无效，请重新上传');
        }
    }

    private static function memberDir(int $memberId): string
    {
        return "member/{$memberId}/";
    }

    /**
     * @param string $dir 存储目录（不含日期）
     * @param string[] $allowExt
     */
    private function store(UploadedFile $file, string $scene, string $dir, array $allowExt, int $adminId): array
    {
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        if (!in_array($ext, $allowExt, true)) {
            throw new BusinessException('不支持的图片格式');
        }

        $real = (string) $file->getRealPath();
        $width = $height = 0;
        if ($ext !== 'ico') {
            $info = @getimagesize($real);
            if ($info === false) {
                throw new BusinessException('图片文件无效');
            }
            [$width, $height] = [(int) $info[0], (int) $info[1]];
        }

        $driver = $this->drivers->storage();
        $path = $dir . '/' . date('Y/m/d') . '/' . Str::random(32) . '.' . $ext;

        try {
            $driver->putFile($path, $file);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            // 买家上传不暴露存储服务的报错细节
            throw new BusinessException($adminId > 0 ? '文件保存失败：' . Str::limit($e->getMessage(), 200) : '图片上传失败，请稍后再试');
        }

        $row = UploadFile::query()->create([
            'driver'   => $driver->code(),
            'scene'    => $scene,
            'path'     => $path,
            'name'     => Str::limit($file->getClientOriginalName(), 250, ''),
            'ext'      => $ext,
            'mime'     => (string) $file->getMimeType(),
            'size'     => (int) $file->getSize(),
            'md5'      => (string) md5_file($real),
            'width'    => $width,
            'height'   => $height,
            'admin_id' => $adminId,
        ]);

        return $this->format($row, $driver);
    }

    private function format(UploadFile $row, StorageDriver $driver): array
    {
        return [
            'id'     => $row->id,
            'url'    => $driver->url($row->path),
            'name'   => $row->name,
            'size'   => (int) $row->size,
            'width'  => (int) $row->width,
            'height' => (int) $row->height,
        ];
    }
}
