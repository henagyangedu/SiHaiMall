<?php

namespace App\Services\Common;

use App\Models\Coupon;
use App\Models\MemberCoupon;
use App\Models\Order;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 优惠券的核心规则（后台发放、买家领取、下单用券都经过这里）。
 *
 * - 类型：满减券（满 threshold 减 amount）、折扣券（满 threshold 打 discount 折，最多减 max_amount）。
 * - 适用范围：全部商品 / 指定分类（含下级）/ 指定商品。门槛和折扣都只按适用范围内的商品金额算。
 * - 有效期在领到手的那一刻固化到 gm_member_coupon，之后后台再改券的设置，不影响已经领到的。
 * - 发行量：received 在发一张的同一条 UPDATE 里加一并校验没有超发（靠行锁，不会超发）。
 * - 用券：一单一张。券面金额（门槛、面额、封顶）是按基准币种（人民币）定的；其他币种的订单按下单币种的汇率换算后再比、再减
 *   （local()，和商品价格用的是同一个汇率、同样按币种的小数位舍入）。给买家看的券也按他的币种换算好（localize()）。
 *   门槛按“适用范围内的商品金额”（原价）判断；能减的钱 = 满减券的面额，或折扣券对“适用商品扣掉会员折扣后的金额”打折（可封顶）；
 *   再怎么减也不会把商品部分减到 0（至少留 0.01，支付渠道不收 0 元单）。计算顺序：会员折扣 → 优惠券 → 积分抵扣，运费不参与。
 * - 退回：订单取消或超时未付款关闭时退回（release）；已付款订单的退款不退券。
 */
class CouponService
{
    public function __construct(private PriceService $price) {}

    /** 把一个按基准币种定的金额换成指定币种的；本来就是基准币种时原样返回 */
    private function local(string $baseAmount, string $currency): string
    {
        $cur = $this->price->currency($currency);

        return bccomp((string) $cur['rate'], '1', 8) === 0 ? bcadd($baseAmount, '0', 2) : Money::fromBase($baseAmount, (string) $cur['rate'], (int) $cur['decimals']);
    }

    /**
     * 把给买家看的一张券（couponRow / memberRow 的结果）里的金额换成他的币种，并标上 currency。不传币种就是基准币种。
     * @param array<string,mixed> $row
     */
    private function localize(array $row, ?string $currency): array
    {
        $code = $this->price->currency((string) $currency)['code'];
        foreach (['threshold', 'amount', 'max_amount', 'face_amount'] as $k) {
            if (isset($row[$k])) {
                $row[$k] = $this->local((string) $row[$k], $code);
            }
        }

        return $row + ['currency' => $code];
    }

    /**
     * 给一个会员发一张券。$bySelf=true 是买家自己领（要求券公开可领、在领取时间内、没超过每人限领）；
     * false 是后台发放（只受发行总量限制）。必须在事务里调用。
     */
    public function issue(Coupon $coupon, int $memberId, bool $bySelf): MemberCoupon
    {
        if ((int) $coupon->status !== 1) {
            throw new BusinessException('这张优惠券已停用');
        }
        $now = now();
        [$start, $end] = self::validPeriod($coupon, $now);
        if ($end->lte($now)) {
            throw new BusinessException('这张优惠券已过期');
        }
        if ($bySelf) {
            if ((int) $coupon->is_public !== 1) {
                throw new BusinessException('这张优惠券不能领取');
            }
            if (($coupon->receive_start && $coupon->receive_start->gt($now)) || ($coupon->receive_end && $coupon->receive_end->lte($now))) {
                throw new BusinessException('不在领取时间内');
            }
            if (MemberCoupon::query()->where('member_id', $memberId)->where('coupon_id', $coupon->id)->count() >= (int) $coupon->per_member) {
                throw new BusinessException('已经领过了');
            }
        }

        // 发行量：加一和“没超发”的判断放在同一条语句里，同时来多少人都不会超发
        $ok = DB::table('coupon')->where('id', $coupon->id)
            ->where(fn ($q) => $q->where('total', 0)->orWhereColumn('received', '<', 'total'))
            ->update(['received' => DB::raw('received + 1')]);
        if (!$ok) {
            throw new BusinessException('优惠券已经领完了');
        }

        return MemberCoupon::query()->create([
            'member_id' => $memberId, 'coupon_id' => $coupon->id, 'status' => MemberCoupon::UNUSED,
            'valid_start' => $start, 'valid_end' => $end,
            'source' => $bySelf ? MemberCoupon::SOURCE_SELF : MemberCoupon::SOURCE_ADMIN, 'created_at' => $now,
        ]);
    }

    /**
     * 这张券现在领的话，有效期是哪一段。
     * @return array{0:Carbon,1:Carbon}
     */
    public static function validPeriod(Coupon $coupon, Carbon $now): array
    {
        if ((int) $coupon->valid_type === Coupon::VALID_DAYS) {
            return [$now->copy(), $now->copy()->addDays(max(1, (int) $coupon->valid_days))];
        }

        return [$coupon->valid_start->copy(), $coupon->valid_end->copy()];
    }

    /**
     * 这张券用在这一单上能减多少。
     * @param array<int,array{goods_id:int,category_id:int,amount:string}> $lines 订单里的每个商品行（amount 是这一行的商品金额，下单币种）
     * @param callable(string):string $net 把一笔商品金额扣掉会员折扣后剩多少
     * @return array{usable:bool,amount:string,reason:string}
     */
    public function quote(Coupon $coupon, array $lines, callable $net, string $currency, string $baseCurrency): array
    {
        $no = fn (string $reason) => ['usable' => false, 'amount' => '0.00', 'reason' => $reason];
        // 券面金额是基准币种的：换成下单币种再用
        $threshold = $this->local((string) $coupon->threshold, $currency);
        $maxAmount = $this->local((string) $coupon->max_amount, $currency);

        $applicable = '0.00';
        $total = '0.00';
        $scopeIds = $this->scopeIds($coupon);
        foreach ($lines as $l) {
            $total = bcadd($total, (string) $l['amount'], 2);
            $hit = match ((int) $coupon->scope) {
                Coupon::SCOPE_CATEGORY => in_array((int) $l['category_id'], $scopeIds, true),
                Coupon::SCOPE_GOODS => in_array((int) $l['goods_id'], $scopeIds, true),
                default => true,
            };
            if ($hit) {
                $applicable = bcadd($applicable, (string) $l['amount'], 2);
            }
        }
        if (bccomp($applicable, '0', 2) <= 0) {
            return $no('订单里没有这张券适用的商品');
        }
        if (bccomp($applicable, $threshold, 2) < 0) {
            $short = self::trim(bcsub($threshold, $applicable, 2));

            return $no(strtoupper($currency) === strtoupper($baseCurrency) ? "适用商品还差 {$short} 元可用" : "适用商品还差 {$short} " . strtoupper($currency) . ' 可用');
        }

        $applicableNet = $net($applicable);
        if ((int) $coupon->type === Coupon::TYPE_REDUCE) {
            $amount = $this->local((string) $coupon->amount, $currency);
        } else {
            $amount = Money::round(bcdiv(bcmul($applicableNet, bcsub('100', (string) $coupon->discount, 2), 6), '100', 6), 2);
            if (bccomp($maxAmount, '0', 2) > 0 && bccomp($amount, $maxAmount, 2) > 0) {
                $amount = $maxAmount;
            }
        }
        // 不能减得比适用商品实际要付的还多，也不能把整单的商品部分减到 0
        $cap = bcsub($net($total), '0.01', 2);
        foreach ([$applicableNet, $cap] as $limit) {
            if (bccomp($amount, $limit, 2) > 0) {
                $amount = $limit;
            }
        }
        if (bccomp($amount, '0', 2) <= 0) {
            return $no('这一单用不上这张券');
        }

        return ['usable' => true, 'amount' => bcadd($amount, '0', 2), 'reason' => ''];
    }

    /**
     * 会员手里现在能用的券（未使用、在有效期内），带上“这一单能不能用、能减多少”。能用的排前面，减得多的在前。
     * @return array<int,array<string,mixed>>
     */
    public function forOrder(int $memberId, array $lines, callable $net, string $currency, string $baseCurrency): array
    {
        $now = now();
        $rows = MemberCoupon::query()->where('member_id', $memberId)->where('status', MemberCoupon::UNUSED)
            ->where('valid_start', '<=', $now)->where('valid_end', '>', $now)->orderBy('valid_end')->get();
        $coupons = Coupon::withTrashed()->whereIn('id', $rows->pluck('coupon_id')->unique())->get()->keyBy('id');

        $out = [];
        foreach ($rows as $r) {
            if (!($c = $coupons->get($r->coupon_id))) {
                continue;
            }
            // 用 array_merge 而不是 +：quote() 的 amount（这一单能减多少）要盖掉券本身的 amount（面额），面额另有 face_amount
            $out[] = array_merge($this->localize(self::memberRow($r, $c), $currency), $this->quote($c, $lines, $net, $currency, $baseCurrency));
        }
        usort($out, fn ($a, $b) => [$b['usable'], (float) $b['amount']] <=> [$a['usable'], (float) $a['amount']]);

        return $out;
    }

    /**
     * 下单时用掉一张券：锁住这张券，确认是本人的、没用过、在有效期内、这一单能用，返回能减的金额。必须在事务里调用。
     * @return array{id:int,amount:string}
     */
    public function useForOrder(int $memberId, int $memberCouponId, array $lines, callable $net, string $currency, string $baseCurrency): array
    {
        $mc = MemberCoupon::query()->where('member_id', $memberId)->lockForUpdate()->find($memberCouponId) ?? throw new BusinessException('优惠券不存在');
        $now = now();
        if ((int) $mc->status !== MemberCoupon::UNUSED) {
            throw new BusinessException('这张优惠券已经用过了');
        }
        if ($mc->valid_start->gt($now) || $mc->valid_end->lte($now)) {
            throw new BusinessException('这张优惠券不在有效期内');
        }
        $coupon = Coupon::withTrashed()->find($mc->coupon_id) ?? throw new BusinessException('优惠券不存在');
        $q = $this->quote($coupon, $lines, $net, $currency, $baseCurrency);
        if (!$q['usable']) {
            throw new BusinessException('这张优惠券不能用于本单：' . $q['reason']);
        }

        return ['id' => (int) $mc->id, 'amount' => $q['amount']];
    }

    /** 订单创建成功后把券标成已使用。必须和 useForOrder 在同一个事务里 */
    public function markUsed(int $memberCouponId, int $orderId): void
    {
        MemberCoupon::query()->whereKey($memberCouponId)->where('status', MemberCoupon::UNUSED)
            ->update(['status' => MemberCoupon::USED, 'order_id' => $orderId, 'used_at' => now()]);
    }

    /** 订单取消或超时未付款关闭：把这张单用掉的券退回去（有效期不延长，过期了就是过期了）。重复调用无副作用 */
    public function release(Order $order): void
    {
        if ((int) $order->member_coupon_id <= 0) {
            return;
        }
        MemberCoupon::query()->whereKey($order->member_coupon_id)->where('order_id', $order->id)->where('status', MemberCoupon::USED)
            ->update(['status' => MemberCoupon::UNUSED, 'order_id' => 0, 'used_at' => null]);
    }

    /**
     * 买家自己领一张券。
     * @return array{id:int,valid_end:string}
     */
    public function receive(int $memberId, int $couponId): array
    {
        return DB::transaction(function () use ($memberId, $couponId) {
            // 锁住券这一行：同一个人同时点两次，“每人限领”才不会被突破
            $coupon = Coupon::query()->lockForUpdate()->find($couponId) ?? throw new BusinessException('优惠券不存在');
            $mc = $this->issue($coupon, $memberId, true);

            return ['id' => (int) $mc->id, 'valid_end' => $mc->valid_end->format('Y-m-d H:i:s')];
        });
    }

    /**
     * 领券中心：现在能领的券（启用、公开、在领取时间内、没领完、没过期）。登录后带上自己领了几张、还能不能领。
     * 传 $goodsId 时只返回这个商品能用的券（商品详情页用）。
     * @return array<int,array<string,mixed>>
     */
    public function receivable(?int $memberId, ?int $goodsId = null, ?string $currency = null): array
    {
        $now = now();
        $coupons = Coupon::query()->where('status', 1)->where('is_public', 1)
            ->where(fn ($q) => $q->whereNull('receive_start')->orWhere('receive_start', '<=', $now))
            ->where(fn ($q) => $q->whereNull('receive_end')->orWhere('receive_end', '>', $now))
            ->where(fn ($q) => $q->where('valid_type', Coupon::VALID_DAYS)->orWhere('valid_end', '>', $now))
            ->where(fn ($q) => $q->where('total', 0)->orWhereColumn('received', '<', 'total'))
            ->orderByDesc('id')->limit(100)->get();

        if ($goodsId) {
            $categoryId = (int) DB::table('goods')->where('id', $goodsId)->value('category_id');
            $coupons = $coupons->filter(fn (Coupon $c) => match ((int) $c->scope) {
                Coupon::SCOPE_CATEGORY => in_array($categoryId, $this->scopeIds($c), true),
                Coupon::SCOPE_GOODS => in_array($goodsId, $this->scopeIds($c), true),
                default => true,
            })->values();
        }
        $mine = $memberId
            ? MemberCoupon::query()->where('member_id', $memberId)->whereIn('coupon_id', $coupons->pluck('id'))->groupBy('coupon_id')->selectRaw('coupon_id, COUNT(*) as c')->pluck('c', 'coupon_id')
            : collect();

        return $coupons->map(function (Coupon $c) use ($mine, $now, $currency) {
            [, $end] = self::validPeriod($c, $now);
            $got = (int) ($mine[$c->id] ?? 0);

            return $this->localize(self::couponRow($c), $currency) + [
                'valid_type' => (int) $c->valid_type, 'valid_days' => (int) $c->valid_days, 'valid_end' => $end->format('Y-m-d H:i:s'),
                'received' => $got, 'can_receive' => $got < (int) $c->per_member,
            ];
        })->all();
    }

    /**
     * 我的优惠券。$status：unused 未使用（含还没到开始时间的）/ used 已使用 / expired 已过期。
     * 过期状态不靠定时任务：未使用但过了有效期的，查的时候就算作已过期。
     */
    public function mine(int $memberId, string $status, int $page, int $size, ?string $currency = null): array
    {
        $now = now();
        $q = MemberCoupon::query()->where('member_id', $memberId);
        match ($status) {
            'used' => $q->where('status', MemberCoupon::USED)->orderByDesc('used_at'),
            'expired' => $q->where(fn ($w) => $w->where('status', MemberCoupon::EXPIRED)->orWhere(fn ($x) => $x->where('status', MemberCoupon::UNUSED)->where('valid_end', '<=', $now)))->orderByDesc('valid_end'),
            default => $q->where('status', MemberCoupon::UNUSED)->where('valid_end', '>', $now)->orderBy('valid_end'),
        };
        $p = $q->paginate(min(max($size, 1), 50), ['*'], 'page', max($page, 1));
        $coupons = Coupon::withTrashed()->whereIn('id', collect($p->items())->pluck('coupon_id')->unique())->get()->keyBy('id');
        $p->setCollection(collect($p->items())->filter(fn ($r) => $coupons->has($r->coupon_id))->map(fn ($r) => $this->localize(self::memberRow($r, $coupons[$r->coupon_id]), $currency))->values());

        return \App\Support\Paginate::format($p);
    }

    /** 一张券给买家看的基本信息 */
    public static function couponRow(Coupon $c): array
    {
        return [
            'coupon_id' => (int) $c->id, 'name' => $c->name, 'type' => (int) $c->type, 'desc' => self::describe($c),
            'threshold' => (string) $c->threshold, 'amount' => (string) $c->amount, 'discount' => (string) $c->discount, 'max_amount' => (string) $c->max_amount,
            'scope' => (int) $c->scope,
        ];
    }

    /** 会员手里的一张券给买家看的信息 */
    private static function memberRow(MemberCoupon $r, Coupon $c): array
    {
        $face = self::couponRow($c);
        // 结算时这里的 amount 会被“这一单能减多少”覆盖（见 forOrder），所以面额另存一份 face_amount
        return ['id' => (int) $r->id, 'face_amount' => $face['amount']] + $face + [
            'valid_start' => $r->valid_start->format('Y-m-d H:i:s'), 'valid_end' => $r->valid_end->format('Y-m-d H:i:s'),
            'status' => (int) $r->status, 'used_at' => $r->used_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 适用范围展开成 ID 列表；指定分类时带上所有下级分类 */
    private function scopeIds(Coupon $c): array
    {
        $ids = array_map('intval', (array) ($c->scope_ids ?? []));
        if ((int) $c->scope !== Coupon::SCOPE_CATEGORY || $ids === []) {
            return $ids;
        }
        $all = $ids;
        $level = $ids;
        // 分类最多三级，逐层往下找
        for ($i = 0; $i < 3 && $level !== []; $i++) {
            $level = DB::table('category')->whereIn('pid', $level)->pluck('id')->map(fn ($v) => (int) $v)->all();
            $all = array_merge($all, $level);
        }

        return array_values(array_unique($all));
    }

    /** 给买家和后台看的一句话说明，如“满 300 减 30”“满 200 打 9 折，最多减 50” */
    public static function describe(Coupon $c): string
    {
        $threshold = bccomp((string) $c->threshold, '0', 2) > 0 ? '满 ' . self::trim((string) $c->threshold) . ' ' : '无门槛';
        if ((int) $c->type === Coupon::TYPE_REDUCE) {
            return $threshold . '减 ' . self::trim((string) $c->amount);
        }
        $zhe = self::trim(bcdiv((string) $c->discount, '10', 2));

        return $threshold . "打 {$zhe} 折" . (bccomp((string) $c->max_amount, '0', 2) > 0 ? '，最多减 ' . self::trim((string) $c->max_amount) : '');
    }

    /** 去掉金额末尾多余的 0：30.00 → 30，9.50 → 9.5 */
    private static function trim(string $n): string
    {
        return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
    }
}
