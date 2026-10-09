<?php

namespace App\Drivers\Contracts;

/** AI 翻译驱动：把 system 提示词和用户内容发给大模型，返回模型输出的文本 */
interface TranslateDriver
{
    /** openai / deepseek / claude / qwen */
    public function code(): string;

    public function model(): string;

    /** $json=true 时要求模型输出 JSON 对象 */
    public function complete(string $system, string $user, bool $json = true): string;
}
