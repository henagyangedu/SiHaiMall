<?php

namespace App\Models;

class MessageTemplate extends BaseModel
{
    /** 目前只接入邮件；表结构预留了短信、公众号、小程序、App 推送、站内信 */
    public const CHANNEL_EMAIL = 'email';

    protected $table = 'message_template';
}
