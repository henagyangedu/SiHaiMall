<?php

namespace App\Drivers\Contracts;

/**
 * 短信驱动（验证码场景）。验证码由短信平台生成、控频并判定有效期，商城不保存验证码本体。
 * $purpose 是商城内部的用途：register / login / reset，驱动负责映射成平台的场景标识。
 */
interface SmsDriver
{
    public function code(): string;

    /** 发送验证码；失败抛 BusinessException（文案可直接展示给用户） */
    public function sendCode(string $purpose, string $mobile): void;

    /** 校验验证码：通过返回 true，错误或过期返回 false；平台或配置故障抛 BusinessException */
    public function checkCode(string $purpose, string $mobile, string $code): bool;

    /** 连通性测试：用已保存的密钥调一个只读接口，失败抛 BusinessException */
    public function ping(): array;
}
