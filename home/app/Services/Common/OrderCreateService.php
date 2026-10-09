<?php

namespace App\Services\Common;

use App\Models\Member;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * 下单核心（C 端接口、后台代下单、测试命令共用）。
 * 价格、汇率、标题、规格、重量全部固化到订单快照，之后商品改价/改名不影响历史订单。
 * 单价由 PriceService::unitPrices 计算；销售区域以收货地址为准，命中的区域记录在订单上。
 * 运费按商品的运费模板计算，规则见 ShippingFeeService。
 */
class OrderCreateService
{
    public function __construct(
        private LocaleService $locale,
        private ConfigService $config,
        private TranslationService $trans,
        private OrderStockService $stock,
        private OrderStatusService $status,
        private ShippingFeeService $shippingFee,
        private PriceService $price,
        private SaleRegionService $regions,
    ) {}

    /**
     * @param array<int,array{sku_id:int,qty:int}> $lines
     * @param array<string,mixed> $address name,phone,phone_area,country_code,province,city,district,address,address2,postcode
     * @param array{currency?:string,lang?:string,source?:string,remark?:string,source_member_id?:int} $opts
     */
    public function create(int $memberId, array $lines, array $address, array $opts = []): Order
    {
        $member = Member::query()->find($memberId) ?? throw new BusinessException('会员不存在');
        if ((int) $member->status !== 1) {
            throw new BusinessException('账号已被禁用，无法下单');
        }

        $need = $this->mergeLines($lines);
        $currency = (string) ($opts['currency'] ?? $member->currency ?: $this->locale->baseCurrency());
        $cur = $this->currencyInfo($currency);
        $lang = (string) ($opts['lang'] ?? $member->lang ?: $this->locale->defaultLang());
        $trade = $this->config->get('trade');

        return DB::transaction(function () use ($member, $need, $address, $opts, $currency, $cur, $lang, $trade) {
            $skus = $this->loadSkus(array_keys($need));
            $needShip = collect($skus)->contains(fn ($s) => (int) $s->is_virtual === 0);
            $address = $needShip ? $this->cleanAddress($address) : [];
            // 区域价以收货地址为准；无需发货的订单没有收货地址，按标准价
            $region = $needShip ? $this->regions->match($address) : null;
            $units = $this->price->unitPrices(collect($skus)->map(fn ($s) => (string) $s->price)->all(), $currency, $region);

            // ---- 逐行算价（下单币种）
            $goodsAmount = '0.00';
            $rows = [];
            foreach ($need as $skuId => $qty) {
                $s = $skus[$skuId];
                $unit = $units[$skuId];
                if (bccomp($unit, '0', 2) <= 0) {
                    throw new BusinessException('商品价格异常，暂时无法下单：' . $s->goods_sn);
                }
                $goodsAmount = bcadd($goodsAmount, Money::mul($unit, $qty), 2);
                $rows[$skuId] = ['sku' => $s, 'qty' => $qty, 'unit' => $unit];
            }

            $minBase = (string) ($trade['min_order_amount'] ?? '0');
            if (bccomp($minBase, '0', 2) > 0 && bccomp(Money::toBase($goodsAmount, $cur['rate']), $minBase, 2) < 0) {
                throw new BusinessException('未达到最低下单金额');
            }

            $shipping =$needShip ? $this->calcShipping($rows, $address, $cur) : '0.00';
            $discount = $this->levelDiscount($member, $goodsAmount);
            $pay = bcsub(bcadd($goodsAmount, $shipping, 2), $discount, 2);
            if (bccomp($pay, '0.01', 2) < 0) {
                $pay = '0.01'; // 支付渠道不接受 0 元单
                $discount = bcsub(bcadd($goodsAmount, $shipping, 2), $pay, 2);
            }

            $orderNo = $this->makeOrderNo();
            $order = Order::query()->create([
                'order_no' => $orderNo, 'member_id' => $member->id,
                'source' => (string) ($opts['source'] ?? $member->register_source ?: ''),
                'lang' => $lang, 'currency' => $currency, 'rate' => $cur['rate'],
                'goods_amount' => $goodsAmount, 'shipping_fee' => $shipping, 'discount_amount' => $discount,
                'points_amount' => '0.00', 'pay_amount' => $pay, 'base_pay_amount' => Money::toBase($pay, $cur['rate']),
                'status' => OrderStatus::PENDING, 'pay_status' => OrderStatus::PAY_UNPAID,
                'remark' => mb_substr((string) ($opts['remark'] ?? ''), 0, 500),
                'source_member_id' => (int) ($opts['source_member_id'] ?? 0),
                'price_region_id' => (int) ($region['id'] ?? 0), 'price_region_name' => (string) ($region['name'] ?? ''),
                'expire_at' => now()->addMinutes((int) ($trade['order_expire_minutes'] ?? 30)),
            ]);

            $titles = $this->trans->fetch('goods_lang', 'goods_id', collect($skus)->pluck('goods_id')->unique()->all(), ['title']);
            $specText = $this->specTexts($skus, $lang);
            foreach ($rows as $skuId => $r) {
                $s = $r['sku'];
                OrderItem::query()->create([
                    'order_id' => $order->id, 'goods_id' => $s->goods_id, 'sku_id' => $skuId,
                    'title' => mb_substr($this->trans->resolve($titles[$s->goods_id]['title'] ?? [], $lang), 0, 255),
                    'spec_text' => mb_substr($specText[$skuId] ?? '', 0, 255),
                    'image' => $s->image ?: $s->cover, 'price' => $r['unit'], 'base_price' => $s->price, 'cost_price' => $s->cost_price,
                    'qty' => $r['qty'], 'weight' => $s->weight,
                ]);
            }
            if ($needShip) {
                OrderAddress::query()->create(['order_id' => $order->id] + $address);
            }

            if (($trade['stock_deduct_mode'] ?? 'order') === 'order') {
                $this->stock->deduct($need, $orderNo);
            }

            $this->status->note($order, OrderStatus::OP_MEMBER, (int) $member->id, '创建订单');

            return $order;
        });
    }

    /**
     * 运费（下单币种）。
     * @param array<int,array{sku:object,qty:int,unit:string}> $rows
     * @param array{rate:string,decimals:int} $cur
     */
    protected function calcShipping(array $rows, array $address, array $cur): string
    {
        $lines = [];
        foreach ($rows as $skuId => $r) {
            $lines[$skuId] = ['qty' => $r['qty'], 'amount' => Money::mul($r['unit'], $r['qty'])];
        }

        return $this->shippingFee->calc($lines, $address, $cur['rate'], $cur['decimals']);
    }

    public function levelDiscount(Member $member, string $goodsAmount): string
    {
        $level = DB::table('member_level')->where('id', $member->level_id)->where('status', 1)->first();
        if (!$level || (float) $level->discount >= 100) {
            return '0.00';
        }
        $rate = bcdiv(bcsub('100', (string) $level->discount, 2), '100', 6);

        return Money::round(bcmul($goodsAmount, $rate, 8), 2);
    }

    /** @return array<int,int> sku_id => qty（同 SKU 合并） */
    private function mergeLines(array $lines): array
    {
        $need = [];
        foreach ($lines as $l) {
            $id = (int) ($l['sku_id'] ?? 0);
            $qty = (int) ($l['qty'] ?? 0);
            if ($id <= 0 || $qty < 1 || $qty > 9999) {
                throw new BusinessException('商品或数量不正确');
            }
            $need[$id] = ($need[$id] ?? 0) + $qty;
        }
        if ($need === []) {
            throw new BusinessException('请选择商品');
        }
        if (count($need) > 100) {
            throw new BusinessException('一次最多下单 100 种商品');
        }

        return $need;
    }

    /** @return array<int,object> sku_id => sku 行（含商品信息），未上架/已删除/已禁用直接拒绝 */
    private function loadSkus(array $ids): array
    {
        $rows = DB::table('goods_sku as s')->join('goods as g', 'g.id', '=', 's.goods_id')
            ->whereIn('s.id', $ids)->whereNull('g.deleted_at')
            ->select('s.*', 'g.is_on_sale', 'g.is_virtual', 'g.cover', 'g.goods_sn')->get()->keyBy('id');

        foreach ($ids as $id) {
            $r = $rows->get($id);
            if (!$r || (int) $r->status !== 1 || (int) $r->is_on_sale !== 1) {
                throw new BusinessException('部分商品已下架或不存在，请刷新后重试');
            }
        }

        return $rows->all();
    }

    private function specTexts(array $skus, string $lang): array
    {
        $valueIds = [];
        foreach ($skus as $s) {
            foreach (array_filter(explode(',', (string) $s->spec_value_ids)) as $v) {
                $valueIds[(int) $v] = true;
            }
        }
        if ($valueIds === []) {
            return [];
        }
        $names = $this->trans->fetch('goods_spec_value_lang', 'value_id', array_keys($valueIds), ['name']);

        $out = [];
        foreach ($skus as $id => $s) {
            $parts = [];
            foreach (array_filter(explode(',', (string) $s->spec_value_ids)) as $v) {
                $parts[] = $this->trans->resolve($names[(int) $v]['name'] ?? [], $lang);
            }
            $out[$id] = implode(' / ', array_filter($parts));
        }

        return $out;
    }

    /** @return array{rate:string,decimals:int} */
    private function currencyInfo(string $code): array
    {
        foreach ($this->locale->currencies() as $c) {
            if ($c['code'] === $code) {
                return ['rate' => $c['is_base'] === 1 ? '1.00000000' : (string) $c['rate'], 'decimals' => (int) $c['decimals']];
            }
        }
        throw new BusinessException('不支持的币种：' . $code);
    }

    private function cleanAddress(array $a): array
    {
        $out = [
            'name' => trim((string) ($a['name'] ?? '')), 'phone_area' => trim((string) ($a['phone_area'] ?? '86')),
            'phone' => trim((string) ($a['phone'] ?? '')), 'country_code' => strtoupper(trim((string) ($a['country_code'] ?? 'CN'))),
            'province' => trim((string) ($a['province'] ?? '')), 'city' => trim((string) ($a['city'] ?? '')),
            'district' => trim((string) ($a['district'] ?? '')), 'address' => trim((string) ($a['address'] ?? '')),
            'address2' => trim((string) ($a['address2'] ?? '')), 'postcode' => trim((string) ($a['postcode'] ?? '')),
        ];
        if ($out['name'] === '' || $out['phone'] === '' || $out['address'] === '' || $out['country_code'] === '') {
            throw new BusinessException('请填写完整的收货信息（收件人、电话、国家、详细地址）');
        }

        return array_map(fn ($v) => mb_substr($v, 0, 255), $out);
    }

    private function makeOrderNo(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $no = 'GM' . date('ymdHis') . random_int(100000, 999999);
            if (!Order::query()->withTrashed()->where('order_no', $no)->exists()) {
                return $no;
            }
        }
        throw new BusinessException('系统繁忙，请重试');
    }
}
