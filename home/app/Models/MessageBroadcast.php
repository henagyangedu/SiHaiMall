<?php

namespace App\Models;

/** 后台群发的站内信（发什么、发给谁、发到哪了） */
class MessageBroadcast extends BaseModel
{
    public const TARGET_ALL = 1;      // 全部会员
    public const TARGET_LEVEL = 2;    // 指定等级
    public const TARGET_MEMBER = 3;   // 指定会员

    public const STATUS_PENDING = 0;  // 待发送
    public const STATUS_SENDING = 1;  // 发送中
    public const STATUS_DONE = 2;     // 已完成
    public const STATUS_REVOKED = 3;  // 已撤回

    /** 写进站内信时的场景标识 */
    public const SCENE = 'broadcast';

    protected $table = 'message_broadcast';

    protected function casts(): array
    {
        return ['target_ids' => 'array'];
    }
}
