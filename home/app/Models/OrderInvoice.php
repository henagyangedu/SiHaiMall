<?php

namespace App\Models;

/** 订单发票（一张订单一张） */
class OrderInvoice extends BaseModel
{
    public const TYPE_PERSONAL = 1;   // 个人
    public const TYPE_COMPANY = 2;    // 企业

    public const PENDING = 0;   // 待开票
    public const ISSUED = 1;    // 已开票
    public const REJECTED = 2;  // 已驳回
    public const VOIDED = 3;    // 已作废

    /** 企业选填的开票资料 */
    public const COMPANY_FIELDS = ['address', 'phone', 'bank', 'bank_account'];

    protected $table = 'order_invoice';

    protected function casts(): array
    {
        return ['company_info' => 'array', 'issued_at' => 'datetime'];
    }
}
