<?php

namespace App\Models;

class MemberPointLog extends BaseModel
{
    protected $table = 'member_point_log';

    public $timestamps = false;

    public const TYPE_TEXT = [1 => '注册奖励', 2 => '签到', 3 => '消费获得', 4 => '下单抵扣', 5 => '后台调整', 6 => '退款扣回'];
}
