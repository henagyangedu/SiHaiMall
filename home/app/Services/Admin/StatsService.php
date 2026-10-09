<?php

namespace App\Services\Admin;

use App\Models\DistributionWithdraw;
use App\Models\Distributor;
use App\Models\OrderRefund;
use App\Services\Common\LocaleService;
use App\Support\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 数据中心（只读统计）。口径：
 * - 金额一律折成基准币种：订单用下单时固化的 base_pay_amount，退款和商品金额按订单固化的汇率折算。
 * - 支付订单 / 支付金额按支付时间统计，包含之后退款的订单；退款金额按退款完成时间单独统计，不从支付金额里扣。
 * - “今日”等日期按系统时区（config app.timezone）的自然日划分。
 */
class StatsService
{
    private const PAID = [OrderStatus::PAY_PAID, OrderStatus::PAY_PART_REFUND, OrderStatus::PAY_REFUNDED];

    public function __construct(private LocaleService $locale) {}

    /** 概览：今日 / 昨日 / 近 7 天 / 近 30 天的核心指标，以及需要处理的待办数量 */
    public function overview(): array
    {
        $today = CarbonImmutable::today();
        $ranges = [
            'today'     => [$today, $today->endOfDay()],
            'yesterday' => [$today->subDay(), $today->subDay()->endOfDay()],
            'last7'     => [$today->subDays(6), $today->endOfDay()],
            'last30'    => [$today->subDays(29), $today->endOfDay()],
        ];

        $periods = [];
        foreach ($ranges as $key => [$from, $to]) {
            $paid = DB::table('order')->whereIn('pay_status', self::PAID)->whereBetween('paid_at', [$from, $to])
                ->selectRaw('count(*) as c, coalesce(sum(base_pay_amount), 0) as amount, count(distinct member_id) as buyers')->first();
            $periods[$key] = [
                'paid_orders' => (int) $paid->c, 'paid_amount' => self::money($paid->amount), 'buyers' => (int) $paid->buyers,
                'avg_order' => (int) $paid->c > 0 ? bcdiv(self::money($paid->amount), (string) $paid->c, 2) : '0.00',
                'refund_amount' => $this->refundAmount($from, $to),
                'new_members' => DB::table('member')->whereBetween('created_at', [$from, $to])->count(),
            ];
        }

        return [
            'base_currency' => $this->locale->baseCurrency(),
            'timezone' => (string) config('app.timezone'),
            'periods' => $periods,
            'todo' => [
                'to_ship' => DB::table('order')->where('status', OrderStatus::TO_SHIP)->whereNull('deleted_at')->count(),
                'refund_pending' => DB::table('order_refund')->whereIn('status', [OrderRefund::PENDING, OrderRefund::WAIT_RECEIVE, OrderRefund::REFUNDING])->count(),
                'withdraw_pending' => DB::table('distribution_withdraw')->whereIn('status', [DistributionWithdraw::PENDING, DistributionWithdraw::APPROVED])->count(),
                'distributor_pending' => DB::table('distributor')->where('status', Distributor::PENDING)->count(),
                'low_stock' => DB::table('goods_sku')->where('status', 1)->where('warn_stock', '>', 0)->whereColumn('stock', '<=', 'warn_stock')->count(),
            ],
            'totals' => [
                'members' => DB::table('member')->whereNull('deleted_at')->count(),
                'goods_on_sale' => DB::table('goods')->whereNull('deleted_at')->where('is_on_sale', 1)->count(),
            ],
        ];
    }

    /** 每日趋势：最近 $days 天（含今天），没有数据的日期补 0 */
    public function trend(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $to = CarbonImmutable::today()->endOfDay();
        $from = CarbonImmutable::today()->subDays($days - 1);
        $p = DB::getTablePrefix();

        $paid = DB::table('order')->whereIn('pay_status', self::PAID)->whereBetween('paid_at', [$from, $to])
            ->selectRaw('date(paid_at) as d, count(*) as c, coalesce(sum(base_pay_amount), 0) as amount')->groupBy('d')->get()->keyBy('d');
        $members = DB::table('member')->whereBetween('created_at', [$from, $to])
            ->selectRaw('date(created_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');
        $refunds = DB::table('order_refund')->join('order', 'order.id', '=', 'order_refund.order_id')
            ->where('order_refund.status', OrderRefund::DONE)->whereBetween('order_refund.finished_at', [$from, $to])
            ->selectRaw("date({$p}order_refund.finished_at) as d, coalesce(sum({$p}order_refund.refund_amount / {$p}order.rate), 0) as amount")
            ->groupBy('d')->pluck('amount', 'd');

        $rows = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $d = $day->toDateString();
            $rows[] = [
                'date' => $d,
                'paid_amount' => self::money($paid[$d]->amount ?? 0), 'paid_orders' => (int) ($paid[$d]->c ?? 0),
                'refund_amount' => self::money($refunds[$d] ?? 0), 'new_members' => (int) ($members[$d] ?? 0),
            ];
        }

        return ['days' => $days, 'base_currency' => $this->locale->baseCurrency(), 'rows' => $rows];
    }

    /** 商品销售排行：最近 $days 天支付的订单，按净销量（扣除已退数量）排序 */
    public function goodsRank(int $days, int $limit = 10): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $from = CarbonImmutable::today()->subDays($days - 1);
        $p = DB::getTablePrefix();

        return DB::table('order_item')->join('order', 'order.id', '=', 'order_item.order_id')
            ->whereIn('order.pay_status', self::PAID)->where('order.paid_at', '>=', $from)
            ->groupBy('order_item.goods_id')
            ->selectRaw("{$p}order_item.goods_id as goods_id, max({$p}order_item.title) as title, max({$p}order_item.image) as image,
                sum({$p}order_item.qty - {$p}order_item.refund_qty) as qty,
                sum({$p}order_item.price * ({$p}order_item.qty - {$p}order_item.refund_qty) / {$p}order.rate) as amount,
                count(distinct case when {$p}order_item.qty > {$p}order_item.refund_qty then {$p}order.id end) as orders")
            ->havingRaw('qty > 0')->orderByDesc('qty')->orderByDesc('amount')->limit(min(max($limit, 1), 50))->get()
            ->map(fn ($r) => [
                'goods_id' => (int) $r->goods_id, 'title' => $r->title, 'image' => $r->image,
                'qty' => (int) $r->qty, 'amount' => self::money($r->amount), 'orders' => (int) $r->orders,
            ])->all();
    }

    /** 分布：最近 $days 天支付的订单，按来源端、下单币种、收货国家/地区、定价区域分组 */
    public function breakdown(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $from = CarbonImmutable::today()->subDays($days - 1);
        $base = fn () => DB::table('order')->whereIn('order.pay_status', self::PAID)->where('order.paid_at', '>=', $from);
        $fmt = fn ($rows, callable $label) => $rows->map(fn ($r) => [
            'key' => (string) $r->k, 'label' => $label((string) $r->k), 'orders' => (int) $r->c, 'amount' => self::money($r->amount),
        ])->all();
        $agg = 'count(*) as c, coalesce(sum(base_pay_amount), 0) as amount';
        $p = DB::getTablePrefix();

        $sources = ['h5' => 'H5', 'mp' => '小程序', 'app' => 'App', 'pc' => 'PC'];

        return [
            'source' => $fmt($base()->selectRaw("source as k, {$agg}")->groupBy('k')->orderByDesc('amount')->get(), fn ($k) => $sources[$k] ?? ($k === '' ? '未知' : $k)),
            'currency' => $fmt($base()->selectRaw("currency as k, {$agg}")->groupBy('k')->orderByDesc('amount')->get(), fn ($k) => $k),
            'country' => $fmt(
                $base()->leftJoin('order_address', 'order_address.order_id', '=', 'order.id')
                    ->selectRaw("coalesce({$p}order_address.country_code, '') as k, count(*) as c, coalesce(sum({$p}order.base_pay_amount), 0) as amount")
                    ->groupBy('k')->orderByDesc('amount')->limit(20)->get(),
                fn ($k) => $k === '' ? '无需发货' : $k
            ),
            'region' => $fmt($base()->selectRaw("price_region_name as k, {$agg}")->groupBy('k')->orderByDesc('amount')->limit(20)->get(), fn ($k) => $k === '' ? '标准价（未命中区域）' : $k),
        ];
    }

    /** 时间段内完成的退款，折成基准币种 */
    private function refundAmount(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $p = DB::getTablePrefix();
        $v = DB::table('order_refund')->join('order', 'order.id', '=', 'order_refund.order_id')
            ->where('order_refund.status', OrderRefund::DONE)->whereBetween('order_refund.finished_at', [$from, $to])
            ->selectRaw("coalesce(sum({$p}order_refund.refund_amount / {$p}order.rate), 0) as amount")->value('amount');

        return self::money($v);
    }

    private static function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
