<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** 邮箱验证码邮件。中文语言发中文，其他语言一律发英文 */
class VerifyCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $minutes,
        public readonly string $siteName,
        public readonly string $lang,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->zh()
            ? "【{$this->siteName}】验证码 {$this->code}"
            : "[{$this->siteName}] Your verification code is {$this->code}");
    }

    public function content(): Content
    {
        $site = e($this->siteName);
        $code = e($this->code);
        if ($this->zh()) {
            $action = $this->purpose === 'reset' ? '重置密码' : '注册账号';
            $lines = ["您正在{$action}，验证码是：", "验证码 {$this->minutes} 分钟内有效，请勿告诉他人。", '如果这不是您本人的操作，请忽略本邮件。'];
        } else {
            $action = $this->purpose === 'reset' ? 'reset your password' : 'create your account';
            $lines = ["Use this code to {$action}:", "The code expires in {$this->minutes} minutes. Do not share it with anyone.", 'If you did not request this, you can safely ignore this email.'];
        }

        return new Content(htmlString: <<<HTML
            <div style="font-family:Arial,'PingFang SC','Microsoft YaHei',sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#303133">
              <h2 style="margin:0 0 16px;font-size:18px">{$site}</h2>
              <p style="margin:0 0 12px">{$lines[0]}</p>
              <p style="margin:0 0 16px;font-size:30px;font-weight:bold;letter-spacing:6px">{$code}</p>
              <p style="margin:0 0 8px;color:#606266">{$lines[1]}</p>
              <p style="margin:0;color:#909399;font-size:13px">{$lines[2]}</p>
            </div>
            HTML);
    }

    private function zh(): bool
    {
        return str_starts_with(strtolower($this->lang), 'zh');
    }
}
