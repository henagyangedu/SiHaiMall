<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/** 所有业务模型基类：统一时间输出格式。字段赋值由 Service 层控制，故 guarded 为空。 */
abstract class BaseModel extends Model
{
    protected $guarded = [];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
