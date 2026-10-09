<?php

namespace App\Support\Exceptions;

use RuntimeException;

/** 业务异常：Service 层抛出，由全局异常处理统一转成 ApiResponse::fail */
class BusinessException extends RuntimeException
{
    public function __construct(string $message, int $code = 1)
    {
        parent::__construct($message, $code);
    }
}
