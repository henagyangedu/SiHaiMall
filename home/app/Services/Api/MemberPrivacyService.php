<?php

namespace App\Services\Api;

use App\Models\Member;
use App\Models\MemberCoupon;
use App\Models\Order;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 买家的隐私权利：注册时的同意、自助注销账号、导出自己的数据。
 *
 * - 同意：后台填了用户协议或隐私政策时，注册必须明确同意（agreeRequired），同意的时间记在 member.agreed_at。
 * - 注销（cancel）：立即生效、不能恢复。抹掉会员身上的个人信息并删掉只属于他的数据；
 *   订单、售后、发票、支付流水、积分和佣金流水保留（记账和售后追溯要用），订单上的收货信息快照也保留。
 *   有没走完的订单或没结清的钱时不让注销（blockers）。注销后同一个邮箱 / 手机号可以重新注册，算新会员。
 * - 导出（export）：把这个会员在商城里的数据整理成一个数组，由买家端存成 JSON 文件。
 */
class MemberPrivacyService
{
    public function __construct(private ProtocolService $protocols) {}

    /** 注册时是否必须同意协议：后台填了用户协议或隐私政策就必须 */
    public function agreeRequired(string $lang): bool
    {
        foreach ($this->protocols->list($lang) as $p) {
            if (in_array($p['key'], ['user_agreement', 'privacy_policy'], true)) {
                return true;
            }
        }

        return false;
    }

    /** 注册入口用：需要同意而没同意时拒绝；返回要记到 agreed_at 的时间（不需要同意时为 null） */
    public function agreedAt(mixed $agree, string $lang): ?\Illuminate\Support\Carbon
    {
        if (!$this->agreeRequired($lang)) {
            return null;
        }
        if (!filter_var($agree, FILTER_VALIDATE_BOOLEAN)) {
            throw new BusinessException('请先阅读并同意用户协议和隐私政策');
        }

        return now();
    }

    /**
     * 现在为什么不能注销；空数组表示可以。
     * @return string[]
     */
    public function blockers(Member $m): array
    {
        $out = [];
        $open = Order::query()->where('member_id', $m->id)->whereIn('status', [0, 1, 2, 5])->count();
        if ($open > 0) {
            $out[] = "还有 {$open} 张订单没有完成（待支付、待发货、待收货或售后中）";
        }
        if (bccomp((string) $m->balance, '0', 2) > 0) {
            $out[] = '账户里还有余额，请先联系客服处理';
        }
        $d = DB::table('distributor')->where('member_id', $m->id)->first(['pending_amount', 'available_amount', 'frozen_amount']);
        if ($d && bccomp(bcadd(bcadd((string) $d->pending_amount, (string) $d->available_amount, 2), (string) $d->frozen_amount, 2), '0', 2) > 0) {
            $out[] = '还有没结清的佣金（待结算、可提现或提现中）';
        }

        return $out;
    }

    /** 注销前给页面看的信息 */
    public function cancelCheck(Member $m): array
    {
        $blockers = $this->blockers($m);

        return [
            'can_cancel'   => $blockers === [],
            'blockers'     => $blockers,
            'has_password' => (string) $m->password !== '',
            'points'       => (int) $m->points,
            'coupons'      => MemberCoupon::query()->where('member_id', $m->id)->where('status', MemberCoupon::UNUSED)->where('valid_end', '>', now())->count(),
        ];
    }

    /** 注销账号。有密码的要验证密码；没有密码的（微信一键登录注册的）不用 */
    public function cancel(Member $m, string $password): void
    {
        if ((string) $m->password !== '') {
            if ($password === '') {
                throw new BusinessException('请输入登录密码');
            }
            if (!Hash::check($password, $m->password)) {
                throw new BusinessException('密码不正确');
            }
        }

        DB::transaction(function () use ($m) {
            // 锁住会员再查一遍，避免查完之后又下了单
            $m = Member::query()->whereKey($m->id)->lockForUpdate()->first() ?? throw new BusinessException('账号不存在');
            if ($blockers = $this->blockers($m)) {
                throw new BusinessException($blockers[0]);
            }
            $id = (int) $m->id;

            // 只属于这个人的数据：直接删
            foreach (['member_token', 'member_auth', 'member_address', 'cart', 'member_favorite', 'stock_subscribe', 'member_message'] as $table) {
                DB::table($table)->where('member_id', $id)->delete();
            }
            // 没用的优惠券作废（已用的留着，订单上要对得上）
            DB::table('member_coupon')->where('member_id', $id)->where('status', MemberCoupon::UNUSED)->update(['status' => MemberCoupon::EXPIRED]);
            // 评价留着，改成匿名（昵称清空后显示为 ***）
            DB::table('goods_review')->where('member_id', $id)->update(['is_anonymous' => 1]);
            // 分销：停用并抹掉实名，下级不再挂在他名下；佣金和提现的流水保留
            DB::table('distributor')->where('member_id', $id)->update(['status' => 3, 'real_name' => '', 'apply_remark' => '', 'updated_at' => now()]);
            DB::table('member')->where('parent_id', $id)->update(['parent_id' => 0]);

            // 抹掉会员身上的个人信息后软删除。account 不留原值：同一个邮箱 / 手机号之后可以重新注册
            $m->forceFill([
                'account' => 'deleted_' . $id, 'phone' => '', 'phone_area' => '86', 'email' => '', 'password' => '', 'nickname' => '', 'avatar' => '',
                'gender' => 0, 'birthday' => null, 'points' => 0, 'parent_id' => 0, 'distributor_status' => 0, 'status' => 0, 'last_login_ip' => '',
            ])->save();
            $m->delete();
        });
    }

    /** 导出这个会员的数据 */
    public function export(Member $m): array
    {
        $id = (int) $m->id;
        $rows = fn (string $table, array $cols, string $order = 'id') => DB::table($table)->where('member_id', $id)->orderBy($order)->get($cols)
            ->map(fn ($r) => (array) $r)->all();
        $json = fn (?string $v) => $v === null || $v === '' ? [] : (json_decode($v, true) ?: []);

        $orders = DB::table('order')->where('member_id', $id)->whereNull('deleted_at')->orderBy('id')->get([
            'id', 'order_no', 'status', 'pay_status', 'currency', 'goods_amount', 'shipping_fee', 'tax_amount', 'tax_name', 'bundle_amount', 'discount_amount', 'coupon_amount', 'points_amount',
            'pay_amount', 'pay_channel', 'remark', 'created_at', 'paid_at', 'shipped_at', 'finished_at',
        ]);
        $orderIds = $orders->pluck('id')->all();
        $items = DB::table('order_item')->whereIn('order_id', $orderIds)->orderBy('id')->get(['order_id', 'title', 'spec_text', 'price', 'qty'])->groupBy('order_id');
        $addresses = DB::table('order_address')->whereIn('order_id', $orderIds)->get()->keyBy('order_id');
        $orderNos = $orders->pluck('order_no', 'id');

        return [
            'exported_at' => now()->format('Y-m-d H:i:s'),
            'profile' => [
                'id' => $id, 'account' => $m->account, 'nickname' => $m->nickname, 'avatar' => $m->avatar, 'gender' => (int) $m->gender,
                'birthday' => $m->birthday?->format('Y-m-d'), 'phone_area' => $m->phone_area, 'phone' => $m->phone, 'email' => $m->email,
                'lang' => $m->lang, 'currency' => $m->currency, 'points' => (int) $m->points, 'balance' => (string) $m->balance,
                'register_source' => $m->register_source, 'registered_at' => $m->created_at?->format('Y-m-d H:i:s'),
                'agreed_at' => $m->agreed_at ? (string) $m->agreed_at : null, 'last_login_at' => $m->last_login_at ? (string) $m->last_login_at : null,
                'linked_accounts' => DB::table('member_auth')->where('member_id', $id)->pluck('platform')->unique()->values()->all(),
            ],
            'addresses' => DB::table('member_address')->where('member_id', $id)->whereNull('deleted_at')->orderBy('id')
                ->get(['name', 'phone_area', 'phone', 'country_code', 'province', 'city', 'district', 'address', 'address2', 'postcode', 'is_default'])->map(fn ($r) => (array) $r)->all(),
            'orders' => $orders->map(function ($o) use ($items, $addresses) {
                $a = $addresses[$o->id] ?? null;
                $row = (array) $o;
                unset($row['id']);

                return $row + [
                    'items' => ($items[$o->id] ?? collect())->map(fn ($i) => ['title' => $i->title, 'spec' => $i->spec_text, 'price' => (string) $i->price, 'qty' => (int) $i->qty])->values()->all(),
                    'shipping_address' => $a ? [
                        'name' => $a->name, 'phone_area' => $a->phone_area, 'phone' => $a->phone, 'country_code' => $a->country_code, 'province' => $a->province,
                        'city' => $a->city, 'district' => $a->district, 'address' => $a->address, 'address2' => $a->address2, 'postcode' => $a->postcode,
                    ] : null,
                ];
            })->all(),
            'refunds' => DB::table('order_refund')->where('member_id', $id)->orderBy('id')
                ->get(['refund_no', 'order_id', 'type', 'reason', 'images', 'qty', 'currency', 'refund_amount', 'status', 'reject_reason', 'created_at', 'finished_at'])
                ->map(function ($r) use ($orderNos, $json) {
                    $row = (array) $r;
                    $row['order_no'] = (string) ($orderNos[$r->order_id] ?? '');
                    $row['images'] = $json($r->images);
                    unset($row['order_id']);

                    return $row;
                })->all(),
            'invoices' => $rows('order_invoice', ['order_no', 'title_type', 'title', 'tax_no', 'email', 'company_info', 'amount', 'status', 'invoice_no', 'created_at', 'issued_at']),
            'reviews' => DB::table('goods_review')->where('member_id', $id)->orderBy('id')
                ->get(['goods_id', 'rating', 'content', 'images', 'is_anonymous', 'append_content', 'append_images', 'append_at', 'created_at'])
                ->map(fn ($r) => ['images' => $json($r->images), 'append_images' => $json($r->append_images)] + (array) $r)->all(),
            'coupons' => DB::table('member_coupon as mc')->leftJoin('coupon as c', 'c.id', '=', 'mc.coupon_id')->where('mc.member_id', $id)->orderBy('mc.id')
                ->get(['c.name', 'mc.status', 'mc.valid_start', 'mc.valid_end', 'mc.used_at', 'mc.created_at'])->map(fn ($r) => (array) $r)->all(),
            'points_log' => $rows('member_point_log', ['type', 'change_points', 'after_points', 'ref_no', 'created_at']),
            'balance_log' => $rows('member_balance_log', ['type', 'change_amount', 'after_amount', 'ref_no', 'created_at']),
            'favorites' => $rows('member_favorite', ['goods_id', 'created_at']),
            'cart' => $rows('cart', ['sku_id', 'qty', 'created_at']),
            'stock_subscribes' => $rows('stock_subscribe', ['goods_id', 'sku_id', 'status', 'created_at']),
            'messages' => $rows('member_message', ['title', 'content', 'is_read', 'created_at']),
            'distribution' => [
                'account' => ($d = DB::table('distributor')->where('member_id', $id)->first(['status', 'real_name', 'pending_amount', 'available_amount', 'frozen_amount', 'withdrawn_amount', 'approved_at']))
                    ? (array) $d : null,
                'commissions' => $rows('distribution_commission', ['level', 'base_amount', 'amount', 'status', 'settled_at', 'created_at']),
                'withdrawals' => $rows('distribution_withdraw', ['withdraw_no', 'amount', 'method', 'status', 'paid_at', 'created_at']),
            ],
        ];
    }
}
