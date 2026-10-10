<?php

namespace App\Drivers\Contracts;

/** 电子面单驱动统一接口（快递100 等）：向服务商下单取快递单号和面单文件 */
interface WaybillDriver
{
    /** 驱动编码：kuaidi100 ... */
    public function code(): string;

    /** 这家快递公司（商城的快递编码，如 SF、ZTO）能不能取电子面单 */
    public function supports(string $expressCode): bool;

    /**
     * 取号：成功返回快递单号、服务商的任务号和面单文件地址；失败抛 BusinessException（消息里带服务商给的原因）。
     * @param array{
     *   express_code:string,
     *   account:array{partner_id:string,partner_key:string,net:string,temp_id:string},
     *   receiver:array{name:string,mobile:string,address:string},
     *   cargo:string, weight:string, remark:string, order_no:string
     * } $order address 是含省市区的完整地址；weight 单位千克
     * @return array{express_no:string,task_id:string,label_url:string}
     */
    public function create(array $order): array;
}
