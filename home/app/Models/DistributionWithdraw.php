<?php

namespace App\Models;

class DistributionWithdraw extends BaseModel
{
    public const PENDING = 0;   // 待审核
    public const APPROVED = 1;  // 待打款
    public const PAID = 2;      // 已打款
    public const REJECTED = 3;  // 已拒绝

    public const STATUS_TEXT = [0 => '待审核', 1 => '待打款', 2 => '已打款', 3 => '已拒绝'];
    public const METHOD_TEXT = ['bank' => '银行卡', 'paypal' => 'PayPal', 'alipay' => '支付宝', 'wechat' => '微信', 'other' => '其它'];

    protected $table = 'distribution_withdraw';

    protected function casts(): array
    {
        // 收款信息加密存储
        return ['account_info' => 'encrypted:array', 'paid_at' => 'datetime'];
    }
}
