<?php

namespace App\Support;

/** 开放接口的权限。新增接口时在这里登记权限，并在 routes/open.php 里用 open.scope:权限 挂上 */
final class OpenScopes
{
    public const ORDER_READ = 'order.read';
    public const ORDER_SHIP = 'order.ship';
    public const GOODS_READ = 'goods.read';
    public const STOCK_WRITE = 'stock.write';
    public const FINANCE_READ = 'finance.read';

    /** 权限 => 给管理员看的说明 */
    public const ALL = [
        self::ORDER_READ   => '读取订单（含收货信息）',
        self::ORDER_SHIP   => '发货、修改物流单号',
        self::GOODS_READ   => '读取规格和库存',
        self::STOCK_WRITE  => '修改库存',
        self::FINANCE_READ => '读取支付流水和售后单',
    ];
}
