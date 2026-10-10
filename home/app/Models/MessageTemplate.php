<?php

namespace App\Models;

class MessageTemplate extends BaseModel
{
    /** 接入了邮件和站内信；表结构还预留了短信、公众号、小程序、App 推送 */
    public const CHANNEL_EMAIL = 'email';

    /** 站内信这个渠道的记录只存开关（没有记录 = 开启），标题和正文用邮件那条记录的 */
    public const CHANNEL_SITE = 'site';

    protected $table = 'message_template';
}
