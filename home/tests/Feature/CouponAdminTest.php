<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\MemberCoupon;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 后台优惠券：建券的各种校验、有人领过之后哪些能改、停用和删除、发放给指定会员、领取记录 */
class CouponAdminTest extends FeatureTestCase
{
    /** 一张合法的满减券，可以按需覆盖字段 */
    private function payload(array $over = []): array
    {
        return $over + [
            'name' => '满 300 减 30', 'type' => 1, 'threshold' => '300', 'amount' => '30', 'scope' => 1, 'total' => 100, 'per_member' => 1,
            'valid_type' => 1, 'valid_start' => now()->subDay()->format('Y-m-d H:i:s'), 'valid_end' => now()->addDays(30)->format('Y-m-d H:i:s'),
            'is_public' => 1, 'status' => 1,
        ];
    }

    private function create(array $over = []): int
    {
        $r = $this->adminApi('POST', '/marketing/coupon', $this->payload($over));
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));

        return (int) $r->json('data.id');
    }

    private function rejected(array $over): string
    {
        $r = $this->adminApi('POST', '/marketing/coupon', $this->payload($over));
        $this->assertNotSame(0, $r->json('code'), '应该被拒绝：'.json_encode($over, JSON_UNESCAPED_UNICODE));

        return (string) $r->json('msg');
    }

    public function test_create_reduce_and_discount_coupons_and_list_them(): void
    {
        $reduce = $this->create();
        $discount = $this->create(['name' => '全场 9 折', 'type' => 2, 'threshold' => '200', 'amount' => '999', 'discount' => '90', 'max_amount' => '50', 'valid_type' => 2, 'valid_days' => 7, 'valid_start' => null, 'valid_end' => null]);
        $free = $this->create(['name' => '无门槛 5 元', 'threshold' => '0', 'amount' => '5']);

        $list = $this->adminApi('GET', '/marketing/coupon/list');
        $list->assertJsonPath('code', 0)->assertJsonPath('data.total', 3);
        $rows = collect($list->json('data.list'))->keyBy('id');
        $this->assertSame('满 300 减 30', $rows[$reduce]['desc']);
        $this->assertSame('满 200 打 9 折，最多减 50', $rows[$discount]['desc']);
        $this->assertSame('无门槛减 5', $rows[$free]['desc']);
        // 折扣券不保存满减金额；领取后 N 天有效的券不保存固定时间
        $this->assertSame(['0.00', '90.00', 7, null], [$rows[$discount]['amount'], $rows[$discount]['discount'], $rows[$discount]['valid_days'], $rows[$discount]['valid_start']]);
        $this->assertSame([0, 0, false], [$rows[$reduce]['received'], $rows[$reduce]['used'], $rows[$reduce]['ended']]);

        $this->assertSame(1, $this->adminApi('GET', '/marketing/coupon/list?type=2')->json('data.total'));
        $this->assertSame(1, $this->adminApi('GET', '/marketing/coupon/list?keyword=无门槛')->json('data.total'));
    }

    public function test_invalid_coupons_are_rejected(): void
    {
        $this->rejected(['name' => '']);
        $this->assertSame('请填写满减金额', $this->rejected(['amount' => '0']));
        $this->assertSame('满减金额要小于使用门槛', $this->rejected(['threshold' => '30', 'amount' => '30']));
        $this->assertStringContainsString('折扣', $this->rejected(['type' => 2, 'discount' => '0']));
        $this->assertStringContainsString('折扣', $this->rejected(['type' => 2, 'discount' => '100']));
        $this->assertSame('有效期的结束时间必须晚于开始时间', $this->rejected(['valid_end' => now()->subDays(2)->format('Y-m-d H:i:s')]));
        $this->assertSame('请填写有效期的开始和结束时间', $this->rejected(['valid_start' => null]));
        $this->assertSame('请填写领取后多少天内有效', $this->rejected(['valid_type' => 2, 'valid_days' => 0]));
        $this->assertSame('请选择适用的分类', $this->rejected(['scope' => 2, 'scope_ids' => []]));
        $this->assertSame('选择的商品里有不存在的', $this->rejected(['scope' => 3, 'scope_ids' => [999999]]));
        $this->rejected(['valid_start' => '2026/10/01']);
        $this->rejected(['per_member' => 0]);
        $this->assertSame(0, Coupon::query()->count());

        // 指定商品、指定分类：存在的才收
        $goods = (int) DB::table('goods_sku')->where('id', $this->makeSku('100.00', 5))->value('goods_id');
        $id = $this->create(['scope' => 3, 'scope_ids' => [$goods, $goods]]);
        $this->assertSame([$goods], Coupon::query()->find($id)->scope_ids);
    }

    public function test_granting_to_members_respects_stock_and_reports_unknown_accounts(): void
    {
        [$a] = $this->makeMember();
        [$b] = $this->makeMember(['phone' => '13800000001']);
        [$off] = $this->makeMember(['status' => 0]);
        $id = $this->create(['total' => 3, 'is_public' => 0]);

        // 有一个找不到（或被禁用）就整批不发
        $bad = $this->adminApi('POST', "/marketing/coupon/{$id}/grant", ['members' => [$a->account, 'nobody@example.com', (string) $off->id]]);
        $bad->assertJsonPath('code', 1);
        $this->assertStringContainsString('nobody@example.com', (string) $bad->json('msg'));
        $this->assertSame(0, MemberCoupon::query()->count());

        // 可以用账号、手机号、会员 ID；同一个人写了两种写法只发一张
        $this->adminApi('POST', "/marketing/coupon/{$id}/grant", ['members' => [$a->account, (string) $a->id, '13800000001']])
            ->assertJsonPath('code', 0)->assertJsonPath('data.granted', 2);
        $mine = MemberCoupon::query()->where('member_id', $a->id)->firstOrFail();
        $this->assertSame([0, 2], [(int) $mine->status, (int) $mine->source]);
        $this->assertSame(2, (int) Coupon::query()->find($id)->received);

        // 后台发放不受“每人限领”和“是否公开”限制，但不能超过发行总量
        $this->adminApi('POST', "/marketing/coupon/{$id}/grant", ['members' => [$a->account, $b->account]])->assertJsonPath('code', 1);
        $this->adminApi('POST', "/marketing/coupon/{$id}/grant", ['members' => [$a->account]])->assertJsonPath('data.granted', 1);
        $this->assertSame(3, MemberCoupon::query()->count());
        $this->adminApi('POST', "/marketing/coupon/{$id}/grant", ['members' => [$b->account]])->assertJsonPath('code', 1);

        // 停用、已过期的券不能再发
        $stopped = $this->create(['status' => 0]);
        $this->adminApi('POST', "/marketing/coupon/{$stopped}/grant", ['members' => [$a->account]])->assertJsonPath('msg', '这张优惠券已停用');
        $ended = $this->create();
        Coupon::query()->whereKey($ended)->update(['valid_end' => now()->subMinute()]);
        $this->adminApi('POST', "/marketing/coupon/{$ended}/grant", ['members' => [$a->account]])->assertJsonPath('msg', '这张优惠券已过期');
        $this->assertTrue($this->adminApi('GET', '/marketing/coupon/list')->collect('data.list')->firstWhere('id', $ended)['ended']);
        $this->assertNotSame(0, $this->adminApi('POST', "/marketing/coupon/{$id}/grant", ['members' => []])->json('code'));
    }

    public function test_validity_is_fixed_when_the_coupon_is_received(): void
    {
        [$m] = $this->makeMember();
        $this->travelTo(now()->setTime(10, 0));

        // 领取后 7 天有效：从发到手的那一刻算
        $days = $this->create(['valid_type' => 2, 'valid_days' => 7, 'valid_start' => null, 'valid_end' => null, 'total' => 0]);
        $this->adminApi('POST', "/marketing/coupon/{$days}/grant", ['members' => [$m->account]])->assertJsonPath('code', 0);
        $row = MemberCoupon::query()->where('coupon_id', $days)->firstOrFail();
        $this->assertSame([now()->format('Y-m-d H:i:s'), now()->addDays(7)->format('Y-m-d H:i:s')], [$row->valid_start->format('Y-m-d H:i:s'), $row->valid_end->format('Y-m-d H:i:s')]);

        // 记录里按有效期即时显示“已过期”
        $this->assertSame('未使用', $this->adminApi('GET', "/marketing/coupon/{$days}/records")->json('data.list.0.status_text'));
        // （不把时间拨到 8 天后：那样测试用的管理员令牌也会过期。直接把这张券的有效期改到过去）
        MemberCoupon::query()->whereKey($row->id)->update(['valid_end' => now()->subMinute()]);
        $rec = $this->adminApi('GET', "/marketing/coupon/{$days}/records")->json('data.list.0');
        $this->assertSame(['已过期', '后台发放', $m->nickname], [$rec['status_text'], $rec['source_text'], $rec['member']]);
    }

    public function test_editing_after_someone_received_it_and_deleting(): void
    {
        [$m] = $this->makeMember();
        $id = $this->create();

        // 没人领过：什么都能改，也能删
        $this->adminApi('PUT', "/marketing/coupon/{$id}", $this->payload(['amount' => '50', 'threshold' => '500', 'name' => '满 500 减 50']))->assertJsonPath('code', 0);
        $this->assertSame('50.00', (string) Coupon::query()->find($id)->amount);

        $this->adminApi('POST', "/marketing/coupon/{$id}/grant", ['members' => [$m->account]])->assertJsonPath('code', 0);
        $current = fn (array $over = []) => $this->payload($over + ['amount' => '50', 'threshold' => '500', 'name' => '满 500 减 50',
            'valid_start' => Coupon::query()->find($id)->valid_start->format('Y-m-d H:i:s'), 'valid_end' => Coupon::query()->find($id)->valid_end->format('Y-m-d H:i:s')]);

        // 有人领过：面额、门槛、范围、有效期不能改
        foreach ([['amount' => '60'], ['threshold' => '400'], ['type' => 2, 'discount' => '90'], ['valid_end' => now()->addYear()->format('Y-m-d H:i:s')], ['scope' => 2, 'scope_ids' => [DB::table('category')->insertGetId(['pid' => 0, 'status' => 1])]]] as $change) {
            $r = $this->adminApi('PUT', "/marketing/coupon/{$id}", $current($change));
            $this->assertSame(1, $r->json('code'), json_encode($change));
            $this->assertStringContainsString('已经有人领取', (string) $r->json('msg'));
        }
        // 名称、发行量、领取设置可以改；发行量不能改得比已领的还少
        $this->adminApi('PUT', "/marketing/coupon/{$id}", $current(['name' => '新名字', 'total' => 500, 'per_member' => 2, 'is_public' => 0]))->assertJsonPath('code', 0);
        $c = Coupon::query()->find($id);
        $this->assertSame(['新名字', 500, 2, 0, '50.00'], [$c->name, (int) $c->total, (int) $c->per_member, (int) $c->is_public, (string) $c->amount]);
        $this->adminApi('PUT', "/marketing/coupon/{$id}", $current(['total' => 0]))->assertJsonPath('code', 0);
        Coupon::query()->whereKey($id)->update(['received' => 5]);
        $this->adminApi('PUT', "/marketing/coupon/{$id}", $current(['total' => 3]))->assertJsonPath('code', 1);

        // 有人领过的不能删，只能停用；停用不影响已经领到的
        $this->adminApi('DELETE', "/marketing/coupon/{$id}")->assertJsonPath('code', 1);
        $this->adminApi('POST', "/marketing/coupon/{$id}/status", ['status' => 0])->assertJsonPath('code', 0);
        $this->assertSame(0, (int) MemberCoupon::query()->where('coupon_id', $id)->value('status'));

        $fresh = $this->create();
        $this->adminApi('DELETE', "/marketing/coupon/{$fresh}")->assertJsonPath('code', 0);
        $this->assertNull(Coupon::query()->find($fresh));
        $this->assertNotSame(0, $this->adminApi('PUT', '/marketing/coupon/999999', $this->payload())->json('code'));
    }
}
