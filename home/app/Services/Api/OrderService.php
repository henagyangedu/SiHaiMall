<?php

namespace App\Services\Api;

use App\Models\Member;
use App\Models\Order;
use App\Services\Common\CouponService;
use App\Services\Common\ExpressTrackService;
use App\Services\Common\LocaleService;
use App\Services\Common\OrderCloseService;
use App\Services\Common\OrderCreateService;
use App\Services\Common\OrderPointsService;
use App\Services\Common\OrderStatusService;
use App\Services\Common\PriceService;
use App\Services\Common\SaleRegionService;
use App\Services\Common\ShippingFeeService;
use App\Services\Common\TaxService;
use App\Support\ApiMessageTranslator;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use App\Support\OrderStatus;
use App\Support\Paginate;
use App\Services\Common\BundleService;
use App\Services\Common\GoodsReviewService;
use App\Services\Common\OrderInvoiceService;
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
        private PriceService $price,
        private ShippingFeeService $shippingFee,
        private SaleRegionService $regions,
        private OrderPointsService $points,
        private CouponService $coupons,
        private BundleService $bundles,
        private TaxService $tax,
        private LocaleService $locale,
        private ExpressTrackService $expressTracks,
    ) {}

    /**
     * 结算预览（不占库存、不落库）。区域价和运费按所选地址（未选则默认地址）计算；还没有收货地址时按标准价、运费为 0，
     * 以 need_address=true 且 address=null 提示前端先填地址；地址不在配送范围时写入 problems。
     * 返回的 points 是本单积分抵扣的可用情况；传 use_points=true 时抵扣金额计入 pay_amount。
     * 优惠券：不传 member_coupon_id 时自动用减得最多的那张，传 0 表示不用，传具体的 ID 表示指定用那张。
     * @param array{cart_ids?:int[],items?:array<int,array{sku_id:int,qty:int}>,address_id?:int,use_points?:bool} $d
     */
    public function checkout(Member $m, array $d, string $lang, string $currency): array
    {
        $lines = $this->resolveLines($m->id, $d);
        $skuIds = array_column($lines, 'sku_id');
        $briefs = $this->catalog->skuBriefs($skuIds, $lang, $currency);

        // 需要发货时先确定收货地址：区域价和运费都以它为准（与 OrderCreateService 一致）
        $address = null;
        $region = null;
        if (collect($briefs)->contains(fn ($b) => $b['valid'] && !$b['is_virtual'])) {
            $address = !empty($d['address_id'])
                ? $this->addresses->find($m->id, (int) $d['address_id'])
                : collect($this->addresses->list($m->id))->first();
            $region = $address ? $this->regions->match($address->only(['country_code', 'province', 'city'])) : null;
            if ($region) {
                $briefs = $this->catalog->skuBriefs($skuIds, $lang, $currency, $region);
            }
        }

        $items = [];
        $bundleLines = [];
        $goods = '0.00';
        $problems = [];
        $needShip = false;
        $feeLines = [];
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
            $feeLines[$b['sku_id']] = [
                'qty' => ($feeLines[$b['sku_id']]['qty'] ?? 0) + $l['qty'],
                'amount' => bcadd($feeLines[$b['sku_id']]['amount'] ?? '0.00', $line, 2),
            ];
            $items[] = ['sku_id' => $b['sku_id'], 'goods_id' => $b['goods_id'], 'title' => $b['title'], 'spec_text' => $b['spec_text'],
                'image' => $b['image'], 'price' => $b['price'], 'qty' => $l['qty'], 'line_amount' => $line];
            $bundleLines[$b['sku_id']] = ['qty' => $feeLines[$b['sku_id']]['qty'], 'unit' => $b['price'], 'goods_id' => $b['goods_id'], 'category_id' => $b['category_id']];
        }

        // 套餐优惠：排在最前面，减的钱摊到套餐里的商品上，后面的会员折扣、优惠券、积分都按摊完之后的金额算（与 OrderCreateService 一致）
        $bundle = $this->bundles->quote($bundleLines, $currency);
        $afterBundle = bcsub($goods, $bundle['amount'], 2);
        $couponLines = [];
        foreach ($bundleLines as $skuId => $l) {
            $couponLines[] = ['goods_id' => $l['goods_id'], 'category_id' => $l['category_id'], 'amount' => bcsub($feeLines[$skuId]['amount'], $bundle['shares'][$skuId] ?? '0.00', 2)];
        }
        $discount = $this->creator->levelDiscount($m, $afterBundle);
        $shipping = '0.00';
        if ($address) {
            $cur = $this->price->currency($currency);
            try {
                $shipping = $this->shippingFee->calc($feeLines, $address->only(['country_code', 'province']), $cur['rate'], $cur['decimals']);
            } catch (BusinessException $e) {
                $problems[] = ['sku_id' => 0, 'msg' => $e->getMessage()];
            }
        }

        // 优惠券：列出手里的券在这一单能不能用、能减多少。没指定用哪张时自动选减得最多的；member_coupon_id 传 0 表示不用券
        $coupons = $this->coupons->forOrder(
            (int) $m->id, $couponLines, fn (string $amount) => bcsub($amount, $this->creator->levelDiscount($m, $amount), 2), $currency, $this->locale->baseCurrency()
        );
        $usable = array_values(array_filter($coupons, fn ($c) => $c['usable']));
        $picked = null;
        if (!array_key_exists('member_coupon_id', $d) || $d['member_coupon_id'] === null) {
            $picked = $usable[0] ?? null;
        } elseif ((int) $d['member_coupon_id'] > 0) {
            $picked = collect($usable)->firstWhere('id', (int) $d['member_coupon_id']);
            if (!$picked) {
                $problems[] = ['sku_id' => 0, 'msg' => '所选的优惠券不能用于本单'];
            }
        }
        $couponAmount = $picked['amount'] ?? '0.00';

        $cur = $this->price->currency($currency);
        $points = $this->points->quote($m->fresh() ?? $m, bcsub(bcsub($afterBundle, $discount, 2), $couponAmount, 2), $cur['rate'], $cur['decimals']);
        $pointsAmount = !empty($d['use_points']) ? $points['amount'] : '0.00';
        $goodsPaid = bcsub(bcsub(bcsub($afterBundle, $discount, 2), $couponAmount, 2), $pointsAmount, 2);
        // 税：按收货地址的税率（与 OrderCreateService 一致）。价内税不计入应付，只是告诉买家其中含多少税
        $tax = $this->tax->quote($address?->only(['country_code', 'province']), $goodsPaid, $shipping, $cur['decimals']);

        return [
            'items' => $items, 'currency' => $currency, 'goods_amount' => $goods, 'shipping_fee' => $shipping,
            'discount_amount' => $discount, 'coupon_amount' => $couponAmount, 'points_amount' => $pointsAmount,
            // 套餐优惠：bundle_amount 是总共减的，bundles 是用了哪些套餐、各几套
            'bundle_amount' => $bundle['amount'], 'bundles' => $bundle['applied'],
            'tax_amount' => $tax['amount'], 'tax_name' => $tax['name'], 'tax_rate' => self::rateText($tax['rate']), 'tax_included' => $tax['included'],
            'pay_amount' => bcadd(bcadd($goodsPaid, $shipping, 2), $tax['extra'], 2),
            // member_coupon_id：这次预览用的是哪张券（0 表示没用），下单时原样传回；coupons：手里所有有效的券，usable=false 的带 reason
            'member_coupon_id' => (int) ($picked['id'] ?? 0), 'coupons' => $coupons,
            'points' => ['enabled' => $points['enabled'], 'available' => $points['available'], 'usable_points' => $points['points'], 'deduct_amount' => $points['amount']],
            // 订单完成后预计获得的积分（未开启消费得积分时为 0）
            'earn_points' => $this->points->earnQuote($goodsPaid, $cur['rate']),
            'need_address' => $needShip, 'address' => $address, 'problems' => $problems,
            'price_region' => $region ? ['id' => $region['id'], 'name' => $region['name']] : null,
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
                    'use_points' => !empty($d['use_points']), 'member_coupon_id' => (int) ($d['member_coupon_id'] ?? 0),
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
        } elseif ($tab === 'to_review') {
            // 待评价：已完成，且还有没评价、也没有全部退款的商品行（和订单详情里 can_review 的判断一致）
            $q->where('status', OrderStatus::FINISHED)->whereExists(fn ($w) => $w->selectRaw('1')->from('order_item as i')
                ->whereColumn('i.order_id', 'order.id')->whereColumn('i.refund_qty', '<', 'i.qty')
                ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('goods_review as r')->whereColumn('r.order_item_id', 'i.id')));
        }
        $p = $q->paginate(min(max($size, 1), 50), ['*'], 'page', max($page, 1));

        $items = DB::table('order_item')->whereIn('order_id', collect($p->items())->pluck('id'))->get()->groupBy('order_id');
        $p->setCollection(collect($p->items())->map(fn (Order $o) => $this->brief($o, $items->get($o->id, collect()))));

        return Paginate::format($p);
    }

    /**
     * 订单每个包裹的物流轨迹。轨迹内容是快递公司给的原文，不翻译；说明性的 message 按买家语言给。
     * @return array<int,array<string,mixed>>
     */
    public function tracks(int $memberId, string $orderNo, string $lang): array
    {
        $o = $this->own($memberId, $orderNo);

        return array_map(fn (array $t) => [
            'express_name' => $t['express_name'], 'express_no' => $t['express_no'], 'shipped_at' => $t['shipped_at'],
            'status' => $t['status'], 'traces' => $t['traces'], 'message' => ApiMessageTranslator::translate($t['message'], $lang),
        ], $this->expressTracks->forOrder((int) $o->id));
    }

    public function detail(int $memberId, string $orderNo): array
    {
        $o = $this->own($memberId, $orderNo);
        $items = DB::table('order_item')->where('order_id', $o->id)->get();
        $addr = DB::table('order_address')->where('order_id', $o->id)->first();
        $ship = DB::table('shipping')->where('order_id', $o->id)->orderBy('id')->get(['express_code', 'express_name', 'express_no', 'item_ids', 'shipped_at']);

        // 评价：订单完成后，每个商品行可以评价一次（全部退款的不行）
        $canReview = GoodsReviewService::orderCanReview($o);
        $reviewed = $canReview ? app(GoodsReviewService::class)->reviewedItemIds((int) $o->id) : [];
        $appendable = $reviewed ? app(GoodsReviewService::class)->appendableItemIds((int) $o->id) : [];
        $brief = $this->brief($o, $items);
        foreach ($brief['items'] as &$it) {
            $it['reviewed'] = in_array($it['item_id'], $reviewed, true);
            $it['can_review'] = $canReview && !$it['reviewed'] && $it['refund_qty'] < $it['qty'];
            // 评价过、还没追评过的可以追评一次
            $it['can_append'] = in_array($it['item_id'], $appendable, true);
        }
        unset($it);

        return $brief + [
            'remark' => $o->remark, 'cancel_reason' => $o->cancel_reason, 'pay_channel' => $o->pay_channel,
            'paid_at' => $o->paid_at?->format('Y-m-d H:i:s'), 'shipped_at' => $o->shipped_at?->format('Y-m-d H:i:s'),
            'finished_at' => $o->finished_at?->format('Y-m-d H:i:s'),
            'address' => $addr, 'shippings' => $ship,
            // 发票：能不能申请、申请过的话现在是什么状态（详细内容见 GET orders/{orderNo}/invoice）
            'invoice' => app(OrderInvoiceService::class)->brief($o),
            'can_cancel' => (int) $o->status === OrderStatus::PENDING,
            'can_pay' => (int) $o->status === OrderStatus::PENDING && !($o->expire_at && $o->expire_at->isPast()),
            'can_receive' => (int) $o->status === OrderStatus::SHIPPED,
            // 是否可申请售后的精确判断（期限、可退数量）见 GET orders/{orderNo}/refundable
            'can_refund' => in_array((int) $o->pay_status, [OrderStatus::PAY_PAID, OrderStatus::PAY_PART_REFUND], true)
                && in_array((int) $o->status, [OrderStatus::TO_SHIP, OrderStatus::SHIPPED, OrderStatus::FINISHED, OrderStatus::AFTER_SALE], true),
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
    /** 税率去掉末尾多余的 0：7.250 → 7.25，20.000 → 20 */
    public static function rateText(string $rate): string
    {
        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }

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
            'discount_amount' => (string) $o->discount_amount, 'coupon_amount' => (string) $o->coupon_amount, 'bundle_amount' => (string) $o->bundle_amount, 'points_amount' => (string) $o->points_amount, 'pay_amount' => (string) $o->pay_amount,
            'tax_amount' => (string) $o->tax_amount, 'tax_name' => (string) $o->tax_name, 'tax_rate' => self::rateText((string) $o->tax_rate), 'tax_included' => (int) $o->tax_included,
            'created_at' => $o->created_at?->format('Y-m-d H:i:s'), 'expire_at' => $o->expire_at?->format('Y-m-d H:i:s'),
            'items' => collect($items)->map(fn ($i) => [
                'goods_id' => (int) $i->goods_id, 'sku_id' => (int) $i->sku_id, 'title' => $i->title, 'spec_text' => $i->spec_text,
                'image' => $i->image, 'price' => (string) $i->price, 'qty' => (int) $i->qty,
                'item_id' => (int) $i->id, 'refund_qty' => (int) $i->refund_qty,
            ])->values()->all(),
        ];
    }
}
