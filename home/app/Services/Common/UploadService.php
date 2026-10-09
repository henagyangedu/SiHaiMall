<?php

namespace App\Services\Common;

use App\Drivers\Contracts\StorageDriver;
use App\Drivers\DriverManager;
use App\Models\UploadFile;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** 文件上传：写入当前默认存储驱动，并记录到 gm_file。目前支持图片，其它文件类型后续按需加入。 */
class UploadService
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'];

    public function __construct(private DriverManager $drivers) {}

    public function image(UploadedFile $file, string $scene, int $adminId): array
    {
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        if (!in_array($ext, self::IMAGE_EXT, true)) {
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
        $path = $scene . '/' . date('Y/m/d') . '/' . Str::random(32) . '.' . $ext;

        try {
            $driver->putFile($path, $file);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BusinessException('文件保存失败：' . Str::limit($e->getMessage(), 200));
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
