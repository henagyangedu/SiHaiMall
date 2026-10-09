<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** 按消息模板生成的通知邮件。正文是纯文本模板，这里转义后保留换行，套上统一的邮件外观 */
class TemplateMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly string $siteName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $site = e($this->siteName);
        $body = nl2br(e($this->body));

        return new Content(htmlString: <<<HTML
            <div style="font-family:Arial,'PingFang SC','Microsoft YaHei',sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#303133;line-height:1.7">
              <h2 style="margin:0 0 16px;font-size:18px">{$site}</h2>
              <div style="font-size:14px">{$body}</div>
            </div>
            HTML);
    }
}
