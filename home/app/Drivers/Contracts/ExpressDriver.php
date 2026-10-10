<?php

namespace App\Drivers\Contracts;

/** 物流查询驱动统一接口（快递100 等）。只负责向服务商查询，缓存和频率控制在 Common\ExpressTrackService */
interface ExpressDriver
{
    /** 驱动编码：kuaidi100 ... */
    public function code(): string;

    /** 这家快递公司（商城的快递编码，如 SF、DHL）能不能查 */
    public function supports(string $expressCode): bool;

    /**
     * 查询物流轨迹。查不到（单号刚生成、还没揽收）返回 status=0 且 traces 为空，不抛异常；
     * 配置错误、额度用完、网络故障抛 BusinessException。
     * @param string $phone 收件人手机号，部分快递公司（如顺丰）查询时要校验尾号
     * @return array{status:int,traces:array<int,array{time:string,context:string}>,pending?:bool} status：0 暂无 1 运输中 2 已签收 3 异常；traces 新的在前（time 是应用时区的 年-月-日 时:分:秒）；
     *   pending=true 表示服务商刚接到这个单号、还在抓取，过几分钟再查就有了
     */
    public function query(string $expressCode, string $expressNo, string $phone = ''): array;

    /** 连通性测试：验证账号和密钥可用，失败抛 BusinessException */
    public function ping(): array;
}
