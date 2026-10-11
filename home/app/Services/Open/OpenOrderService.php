<?php

namespace App\Services\Open;

use App\Models\GoodsSku;
use App\Models\OpenApp;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Shipping;
use App\Services\Admin\OrderShipService;
use App\Services\Common\OpenAppService;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use Illuminate\Support\Collection;

/** 开放接口：订单的读取和发货。发货、改单号走后台用的同一个服务，规则完全一样 */
class OpenOrderService
{
    public function __construct(private OrderShipService $ships, private OpenAppService $apps) {}

    /**
     * 订单列表，按更新时间从早到晚（增量拉取：下次把这次拿到的最大 updated_at 作为 updated_after）。
     * 买家在自己的订单列表里删掉的订单照样返回。
     * @param array{updated_after?:string,updated_before?:string,status?:int|string,pay_status?:int|string,page?:int|string,page_size?:int|string} $q
     */
    public function list(array $q): array
    {
        $size = min(100, max(1, (int) ($q['page_size'] ?? 50)));
        $p = Order::query()->withTrashed()->with(['items', 'address'])
            ->when(($q['updated_after'] ?? '') !== '', fn ($x) => $x->where('updated_at', '>=', OpenTime::parse($q['updated_after'])))
            ->when(($q['updated_before'] ?? '') !== '', fn ($x) => $x->where('updated_at', '<', OpenTime::parse($q['updated_before'])))
            ->when(($q['status'] ?? '') !== '', fn ($x) => $x->where('status', (int) $q['status']))
            ->when(($q['pay_status'] ?? '') !== '', fn ($x) => $x->where('pay_status', (int) $q['pay_status']))
            ->orderBy('updated_at')->orderBy('id')
            ->paginate($size, ['*'], 'page', max(1, (int) ($q['page'] ?? 1)));

        return ['list' => $this->format(collect($p->items())), 'total' => $p->total(), 'page' => $p->currentPage(), 'page_size' => $p->perPage()];
    }

    public function detail(string $orderNo): array
    {
        return $this->format(collect([$this->find($orderNo)->load(['items', 'address'])]))[0];
    }

    /**
     * 发货。同一张订单用同一个快递公司和单号重复提交时，直接返回上次的发货记录（对方超时重试不会报错，也不会发两次）。
     * @param array{express_code:string,express_name?:string,express_no:string,item_ids?:int[]} $d
     */
    public function ship(string $orderNo, array $d, OpenApp $app): array
    {
        $o = $this->find($orderNo);
        $no = trim((string) $d['express_no']);
        $same = fn () => Shipping::query()->where('order_id', $o->id)->where('express_code', $d['express_code'])->where('express_no', $no)->orderByDesc('id')->first();
        if ($done = $same()) {
            return $this->shipment($done);
        }
        $this->ships->ship((int) $o->id, [
            'express_code' => $d['express_code'], 'express_name' => (string) ($d['express_name'] ?? ''), 'express_no' => $no,
            'item_ids' => (array) ($d['item_ids'] ?? []),
        ], $this->apps->operator($app), $this->apps->label($app));

        return $this->shipment($same());
    }

    /** @param array{express_code:string,express_name?:string,express_no:string} $d */
    public function editShipment(int $shipmentId, array $d, OpenApp $app): array
    {
        $this->ships->editExpress($shipmentId, $d, $this->apps->operator($app), $this->apps->label($app));

        return $this->shipment(Shipping::query()->findOrFail($shipmentId));
    }

    /** 发货时可以用的快递公司编码 */
    public function expressCompanies(): array
    {
        return $this->ships->expressOptions();
    }

    private function find(string $orderNo): Order
    {
        return Order::query()->withTrashed()->where('order_no', $orderNo)->first() ?? throw new BusinessException('订单不存在', 404);
    }

    /** @param Collection<int,Order> $orders */
    private function format(Collection $orders): array
    {
        $ids = $orders->pluck('id')->all();
        $skuSn = GoodsSku::query()->whereIn('id', $orders->flatMap(fn (Order $o) => $o->items->pluck('sku_id'))->unique()->all())->pluck('sku_sn', 'id');
        $shipments = Shipping::query()->whereIn('order_id', $ids)->orderBy('id')->get()->groupBy('order_id');
        $refunded = OrderRefund::query()->whereIn('order_id', $ids)->where('status', OrderRefund::DONE)
            ->selectRaw('order_id, SUM(refund_amount) as total')->groupBy('order_id')->pluck('total', 'order_id');

        return $orders->map(function (Order $o) use ($skuSn, $shipments, $refunded) {
            $a = $o->address;

            return [
                'order_no' => $o->order_no,
                'status' => (int) $o->status, 'status_text' => OrderStatus::TEXT[(int) $o->status] ?? '',
                'pay_status' => (int) $o->pay_status, 'pay_status_text' => OrderStatus::PAY_TEXT[(int) $o->pay_status] ?? '',
                'member_id' => (int) $o->member_id, 'source' => (string) $o->source, 'lang' => (string) $o->lang,
                'currency' => (string) $o->currency, 'rate' => (string) $o->rate,
                // 恒等式：goods_amount + shipping_fee − bundle_amount − discount_amount − coupon_amount − points_amount + 价外税 = pay_amount
                'goods_amount' => (string) $o->goods_amount, 'shipping_fee' => (string) $o->shipping_fee,
                'bundle_amount' => (string) $o->bundle_amount, 'discount_amount' => (string) $o->discount_amount,
                'coupon_amount' => (string) $o->coupon_amount, 'points_amount' => (string) $o->points_amount,
                'tax_amount' => (string) $o->tax_amount, 'tax_name' => (string) $o->tax_name, 'tax_rate' => (string) $o->tax_rate,
                'tax_included' => (bool) $o->tax_included,
                'pay_amount' => (string) $o->pay_amount, 'base_pay_amount' => (string) $o->base_pay_amount,
                'refunded_amount' => number_format((float) ($refunded[$o->id] ?? 0), 2, '.', ''),
                'pay_channel' => (string) $o->pay_channel,
                'remark' => (string) $o->remark,
                'created_at' => OpenTime::format($o->created_at), 'paid_at' => OpenTime::format($o->paid_at),
                'shipped_at' => OpenTime::format($o->shipped_at), 'finished_at' => OpenTime::format($o->finished_at),
                'updated_at' => OpenTime::format($o->updated_at),
                // 无需发货的虚拟商品订单没有收货信息
                'address' => $a ? [
                    'name' => (string) $a->name, 'phone_area' => (string) $a->phone_area, 'phone' => (string) $a->phone,
                    'country_code' => (string) $a->country_code, 'province' => (string) $a->province, 'city' => (string) $a->city,
                    'district' => (string) $a->district, 'address' => (string) $a->address, 'address2' => (string) $a->address2,
                    'postcode' => (string) $a->postcode,
                ] : null,
                'items' => $o->items->map(fn ($i) => [
                    'id' => (int) $i->id, 'goods_id' => (int) $i->goods_id, 'sku_id' => (int) $i->sku_id,
                    // 货号取的是规格现在的货号（订单里没有存）；规格已删除时是空串
                    'sku_sn' => (string) ($skuSn[$i->sku_id] ?? ''),
                    'title' => (string) $i->title, 'spec_text' => (string) $i->spec_text,
                    'price' => (string) $i->price, 'qty' => (int) $i->qty, 'refund_qty' => (int) $i->refund_qty,
                    'shipped' => (int) $i->ship_status === 1, 'weight' => (string) $i->weight,
                ])->values()->all(),
                'shipments' => collect($shipments[$o->id] ?? [])->map(fn (Shipping $s) => $this->shipment($s))->values()->all(),
            ];
        })->values()->all();
    }

    private function shipment(Shipping $s): array
    {
        return [
            'id' => (int) $s->id, 'express_code' => (string) $s->express_code, 'express_name' => (string) $s->express_name,
            'express_no' => (string) $s->express_no,
            'item_ids' => array_values(array_map('intval', array_filter(explode(',', (string) $s->item_ids)))),
            'shipped_at' => OpenTime::format($s->shipped_at),
        ];
    }
}
