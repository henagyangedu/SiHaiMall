<?php

namespace App\Services\Admin;

use App\Drivers\DriverManager;
use App\Drivers\Waybill\Kuaidi100WaybillDriver;
use App\Models\Order;
use App\Models\WaybillAccount;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 电子面单：快递账号的维护，以及为一张订单取号（向服务商下单，拿回快递单号和面单文件）。
 * 取号只在 OrderShipService::shipByWaybill 里用——取到号马上发货，没有“只取号不发货”。
 * 只支持国内收货地址；一次一个包裹（不做子母件）；运费月结。
 */
class WaybillService
{
    public function __construct(private DriverManager $drivers) {}

    /** 电子面单启用了没有 */
    public function enabled(): bool
    {
        return $this->drivers->waybillOrNull() !== null;
    }

    /** 快递账号列表（密码不回显，只说有没有填） */
    public function accounts(): array
    {
        return WaybillAccount::query()->orderBy('id')->get()->map(fn (WaybillAccount $a) => [
            'id' => (int) $a->id, 'express_code' => (string) $a->express_code, 'express_name' => OrderShipService::EXPRESS[$a->express_code] ?? $a->express_code,
            'partner_id' => (string) $a->partner_id, 'has_key' => (string) $a->partner_key !== '', 'net' => (string) $a->net, 'temp_id' => (string) $a->temp_id,
            'remark' => (string) $a->remark, 'status' => (int) $a->status,
        ])->all();
    }

    /** 能配账号的快递公司（国内、驱动支持的） */
    public function expressOptions(): array
    {
        return array_values(array_map(fn ($code) => ['code' => $code, 'name' => OrderShipService::EXPRESS[$code] ?? $code], array_keys(Kuaidi100WaybillDriver::COMPANY)));
    }

    /** 新增或修改快递账号。修改时密码留空表示不改 */
    public function saveAccount(int $id, array $d): void
    {
        $code = strtoupper((string) $d['express_code']);
        if (!isset(Kuaidi100WaybillDriver::COMPANY[$code])) {
            throw new BusinessException('这家快递公司暂不支持电子面单');
        }
        if (WaybillAccount::query()->where('express_code', $code)->where('id', '!=', $id)->exists()) {
            throw new BusinessException('这家快递已经配置过账号了，直接修改它即可');
        }
        $fields = [
            'express_code' => $code, 'partner_id' => trim((string) $d['partner_id']), 'net' => trim((string) ($d['net'] ?? '')),
            'temp_id' => trim((string) ($d['temp_id'] ?? '')), 'remark' => trim((string) ($d['remark'] ?? '')), 'status' => (int) ($d['status'] ?? 1) === 1 ? 1 : 0,
        ];
        $key = (string) ($d['partner_key'] ?? '');
        if ($id > 0) {
            $a = WaybillAccount::query()->find($id) ?? throw new BusinessException('快递账号不存在');
            $a->update($fields + ($key !== '' ? ['partner_key' => $key] : []));

            return;
        }
        WaybillAccount::query()->create($fields + ['partner_key' => $key]);
    }

    public function deleteAccount(int $id): void
    {
        (WaybillAccount::query()->find($id) ?? throw new BusinessException('快递账号不存在'))->delete();
    }

    /** 发货弹窗用：电子面单能不能用、有哪些快递可选 */
    public function shipOptions(): array
    {
        if (!$this->enabled()) {
            return ['enabled' => false, 'expresses' => []];
        }
        $codes = WaybillAccount::query()->where('status', 1)->orderBy('id')->pluck('express_code')->all();

        return ['enabled' => true, 'expresses' => array_map(fn ($c) => ['code' => $c, 'name' => OrderShipService::EXPRESS[$c] ?? $c], $codes)];
    }

    /**
     * 为订单取号。
     * @param int[] $itemIds 这个包裹里的商品行（用来写品名和算默认重量）
     * @param string|null $weight 千克；不传就用这些商品的重量之和，算出来是 0 时按 1 千克
     * @return array{express_code:string,express_no:string,task_id:string,label_url:string}
     */
    public function create(Order $order, string $expressCode, array $itemIds, ?string $weight = null): array
    {
        $driver = $this->drivers->waybillOrNull() ?? throw new BusinessException('电子面单还没有启用，请先到“设置 → API 配置 → 电子面单”里配置');
        $code = strtoupper($expressCode);
        $account = WaybillAccount::query()->where('express_code', $code)->where('status', 1)->first()
            ?? throw new BusinessException('这家快递还没有配置电子面单账号');

        $addr = DB::table('order_address')->where('order_id', $order->id)->first() ?? throw new BusinessException('订单没有收货地址，不能取电子面单');
        if (strtoupper((string) $addr->country_code) !== 'CN') {
            throw new BusinessException('电子面单只支持国内收货地址');
        }
        $items = DB::table('order_item')->where('order_id', $order->id)->whereIn('id', $itemIds)->get(['title', 'qty', 'weight']);
        if ($items->isEmpty()) {
            throw new BusinessException('所选商品不存在');
        }
        if ($weight === null || bccomp($weight, '0', 3) <= 0) {
            $weight = $items->reduce(fn ($c, $i) => bcadd($c, bcmul((string) $i->weight, (string) $i->qty, 3), 3), '0');
            $weight = bccomp($weight, '0', 3) > 0 ? $weight : '1';
        }

        $result = $driver->create([
            'express_code' => $code,
            'account' => ['partner_id' => (string) $account->partner_id, 'partner_key' => (string) $account->partner_key, 'net' => (string) $account->net, 'temp_id' => (string) $account->temp_id],
            'receiver' => [
                'name' => (string) $addr->name, 'mobile' => (string) $addr->phone,
                'address' => implode('', array_filter([(string) $addr->province, (string) $addr->city, (string) $addr->district, (string) $addr->address, (string) ($addr->address2 ?? '')])),
            ],
            'cargo' => (string) $items->first()->title . ($items->count() > 1 ? ' 等' : ''),
            'weight' => rtrim(rtrim(bcadd($weight, '0', 2), '0'), '.'),
            'remark' => '', 'order_no' => (string) $order->order_no,
        ]);

        return ['express_code' => $code] + $result;
    }
}
