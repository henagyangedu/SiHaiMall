<?php

namespace App\Services\Open;

use Illuminate\Support\Carbon;

/** 开放接口里的时间：一律是应用时区（北京时间）的“年-月-日 时:分:秒”，进出都是这个格式 */
final class OpenTime
{
    public static function format(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null;
    }

    public static function parse(string $value): string
    {
        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
