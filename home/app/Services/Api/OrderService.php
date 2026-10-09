<?php

namespace App\Services\Api;

use App\Models\Member;
use App\Models\Order;
use App\Services\Common\OrderCloseService;
use App\Services\Common\OrderCreateService;
use App\Services\Common\OrderStatusService;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use App\Support\OrderStatus;
use App\Support\Paginate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** C 端订单：结算预览、下单、列表、详情、取消、确认收货 */
class OrderService
{
    /** 列表页签 → 订单状态 */
    private const TABS = [
        'unpaid' => [0], 'to_ship' => [1], 'shipped' => [2], 'finished' => [3], 'after_sale' => [5], 'closed' => [4, 6],
    ];

    public function __construct(
        private OrderCreateService $creator,
        private OrderCloseService $closer,
        private OrderStatusService $status,
        private CartService $cart,
        private CatalogService $catalog,
        private AddressService $addresses,
    ) {}

    /**
     * 结算预览（不占库存、不落库）。运费模板模块接入前运费恒为 0，shipping_pending=true 供前端提示。
     * @param array{cart_ids?:int[],items?:array<int,array{sku_id:int,qty:int}>,address_id?:int} $d
     */
    public function checkout(Member $m, array $d, string $lang, string $currency): array
    {
        $lines = $this->resolveLines($m->id, $d);
        $briefs = $this->catalog->skuBriefs(array_column($lines, 'sku_id'), $lang, $currency);

        $items = [];
        $goods = '0.00';
        $problems = [];
        $needShip = false;
        foreach ($lines as $l) {
            $b = $briefs[$l['sku_id']] ?? null;
            if (!$b || !$b['valid']) {
                $problems[] = ['sku_id' => $l['sku_id'], 'msg' => '商品已下架或不存在'];
                continue;
            }
            if ($b['stock'] < $l['qty']) {
                $problems[] = ['sku_id' => $l['sku_id'], 'msg' => '库存不足，仅剩 ' . $b['stock'] . ' 件'];
            }
            $line = Money::mul($b['price'], $l['qty']);
            $goods = bcadd($goods, $line, 2);
            $needShip = $needShip || !$b['is_virtual'];
            $items[] = ['sku_id' => $b['sku_id'], 'goods_id' => $b['goods_id'], 'title' => $b['title'], 'spec_text' => $b['spec_text'],
                'image' => $b['image'], 'price' => $b['price'], 'qty' => $l['qty'], 'line_amount' => $line];
        }

        $discount = $this->creator->levelDiscount($m, $goods);
        $address = null;
        if ($needShip) {
            $address = !empty($d['address_id'])
                ? $this->addresses->find($m->id, (int) $d['address_id'])
                : collect($this->addresses->list($m->id))->first();
        }

        return [
            'items' => $items, 'currency' => $currency, 'goods_amount' => $goods, 'shipping_fee' => '0.00', 'shipping_pending' => $needShip,
            'discount_amount' => $discount, 'pay_amount' => bcsub($goods, $discount, 2),
            'need_address' => $needShip, 'address' => $address, 'problems' => $problems,
        ];
    }

    /**
     * 下单。金额一律以服务端实时计算并固化为准，不接受客户端传价。
     * @param array{cart_ids?:int[],items?:array<int,array{sku_id:int,qty:int}>,address_id?:int,remark?:string} $d
     */
    public function create(Member $m, array $d, string $lang, string $currency, string $source): array
    {
        // 防止重复点击连续提交：同一会员同一时刻只处理一个下单请求
        $lock = Cache::lock('order_create:' . $m->id, 15);
        if (!$lock->get()) {
            throw new BusinessException('订单正在提交，请勿重复操作');
        }
        try {
            $lines = $this->resolveLines($m->id, $d);
            $address = !empty($d['address_id']) ? $this->addresses->find($m->id, (int) $d['address_id'])->only([
                'name', 'phone_area', 'phone', 'country_code', 'province', 'city', 'district', 'address', 'address2', 'postcode',
            ]) : [];

            $order = DB::transaction(function () use ($m, $d, $lines, $address, $lang, $currency, $source) {
                $order = $this->creator->create($m->id, $lines, $address, [
                    'currency' => $currency, 'lang' => $lang, 'source' => $source, 'remark' => (string) ($d['remark'] ?? ''),
                ]);
                if (!empty($d['cart_ids'])) {
                    $this->cart->remove($m->id, array_map('intval', $d['cart_ids']));
                }

                return $order;
            });
        } finally {
            $lock->release();
        }

        return ['order_no' => $order->order_no, 'pay_amount' => (string) $order->pay_amount, 'currency' => $order->currency, 'expire_at' => $order->expire_at?->format('Y-m-d H:i:s')];
    }

    public function list(int $memberId, string $tab, int $page, int $size): array
    {
        $q = Order::query()->where('member_id', $memberId)->orderByDesc('id');
        if (isset(self::TABS[$tab])) {
            $q->whereIn('status', self::TABS[$tab]);
        }
        $p = $q->paginate(min(max($size, 1), 50), ['*'], 'page', max($page, 1));

        $items = DB::table('order_item')->whereIn('order_id', collect($p->items())->pluck('id'))->get()->groupBy('order_id');
        $p->setCollection(collect($p->items())->map(fn (Order $o) => $this->brief($o, $items->get($o->id, collect()))));

        return Paginate::format($p);
    }

    public function detail(int $memberId, string $orderNo): array
    {
        $o = $this->own($memberId, $orderNo);
        $items = DB::table('order_item')->where('order_id', $o->id)->get();
        $addr = DB::table('order_address')->where('order_id', $o->id)->first();
        $ship = DB::table('shipping')->where('order_id', $o->id)->orderBy('id')->get(['express_code', 'express_name', 'express_no', 'item_ids', 'shipped_at']);

        return $this->brief($o, $items) + [
            'remark' => $o->remark, 'cancel_reason' => $o->cancel_reason, 'pay_channel' => $o->pay_channel,
            'paid_at' => $o->paid_at?->format('Y-m-d H:i:s'), 'shipped_at' => $o->shipped_at?->format('Y-m-d H:i:s'),
            'finished_at' => $o->finished_at?->format('Y-m-d H:i:s'),
            'address' => $addr, 'shippings' => $ship,
            'can_cancel' => (int) $o->status === OrderStatus::PENDING,
            'can_pay' => (int) $o->status === OrderStatus::PENDING && !($o->expire_at && $o->expire_at->isPast()),
            'can_receive' => (int) $o->status === OrderStatus::SHIPPED,
        ];
    }

    public function cancel(int $memberId, string $orderNo, string $reason): void
    {
        $o = $this->own($memberId, $orderNo);
        if ((int) $o->status !== OrderStatus::PENDING) {
            throw new BusinessException('订单当前不能取消');
        }
        $this->closer->closeUnpaid($o, OrderStatus::CANCELLED, OrderStatus::OP_MEMBER, $memberId, $reason !== '' ? $reason : '买家取消订单');
    }

    public function receive(int $memberId, string $orderNo): void
    {
        $o = $this->own($memberId, $orderNo);
        DB::transaction(fn () => $this->status->transit(
            $o, [OrderStatus::SHIPPED], OrderStatus::FINISHED, OrderStatus::OP_MEMBER, $memberId, '买家确认收货', ['finished_at' => now()]
        ));
    }

    // ------------------------------------------------------------------ 内部
    public function own(int $memberId, string $orderNo): Order
    {
        return Order::query()->where('member_id', $memberId)->where('order_no', $orderNo)->first() ?? throw new BusinessException('订单不存在');
    }

    /** 购物车结算（cart_ids）或立即购买（items）二选一 */
    private function resolveLines(int $memberId, array $d): array
    {
        if (!empty($d['cart_ids'])) {
            return $this->cart->linesFor($memberId, array_map('intval', $d['cart_ids']));
        }
        if (!empty($d['items'])) {
            return array_map(fn ($i) => ['sku_id' => (int) $i['sku_id'], 'qty' => (int) $i['qty']], $d['items']);
        }
        throw new BusinessException('请选择商品');
    }

    private function brief(Order $o, $items): array
    {
        return [
            'order_no' => $o->order_no, 'status' => (int) $o->status, 'status_text' => OrderStatus::TEXT[(int) $o->status] ?? '',
            'pay_status' => (int) $o->pay_status, 'currency' => $o->currency,
            'goods_amount' => (string) $o->goods_amount, 'shipping_fee' => (string) $o->shipping_fee,
            'discount_amount' => (string) $o->discount_amount, 'pay_amount' => (string) $o->pay_amount,
            'created_at' => $o->created_at?->format('Y-m-d H:i:s'), 'expire_at' => $o->expire_at?->format('Y-m-d H:i:s'),
            'items' => collect($items)->map(fn ($i) => [
                'goods_id' => (int) $i->goods_id, 'sku_id' => (int) $i->sku_id, 'title' => $i->title, 'spec_text' => $i->spec_text,
                'image' => $i->image, 'price' => (string) $i->price, 'qty' => (int) $i->qty,
            ])->values()->all(),
        ];
    }
}
