<?php

namespace App\Models;

/** 电子面单的快递账号（每家快递一条）。密码加密保存，接口里不回显 */
class WaybillAccount extends BaseModel
{
    protected $table = 'waybill_account';

    protected $hidden = ['partner_key'];

    protected function casts(): array
    {
        return ['partner_key' => 'encrypted'];
    }
}
