<?php

namespace App\Models;

/** 上传文件记录（表 gm_file）。业务表里保存上传接口返回的完整 url；这里用于追踪、清理和后续的素材管理。 */
class UploadFile extends BaseModel
{
    protected $table = 'file';

    const UPDATED_AT = null;
}
