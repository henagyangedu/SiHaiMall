<?php

use App\Models\Coupon;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * 优惠券点击测试的准备步骤：建一张买家可以自己领的券（无门槛减 5，名字以“点击测试券”开头，cleanup.php 会删），
 * 并返回一个单规格、有库存的在售商品。测试会员手里上一轮留下的测试券先清掉，保证这一轮是从“没领过”开始。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}

$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
if (!$member) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT')]);

    return;
}
$sku = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 2)
    ->orderBy('g.id')->first(['g.id as goods_id', 'k.id as sku_id', 'k.price']);
if (!$sku) {
    $out(['error' => '找不到单规格、已上架、有库存的商品']);

    return;
}

$old = DB::table('coupon')->where('name', 'like', '点击测试券%')->pluck('id');
DB::table('member_coupon')->whereIn('coupon_id', $old)->where('status', 0)->delete();
DB::table('coupon')->whereIn('id', $old)->delete();

$name = '点击测试券 ' . substr((string) time(), -6);
$coupon = Coupon::query()->create([
    'name' => $name, 'type' => 1, 'threshold' => 0, 'amount' => 5, 'discount' => 0, 'max_amount' => 0, 'scope' => 1, 'scope_ids' => [],
    'total' => 0, 'received' => 0, 'per_member' => 1, 'valid_type' => 2, 'valid_days' => 7, 'is_public' => 1, 'status' => 1,
]);

$out(['coupon_id' => (int) $coupon->id, 'coupon_name' => $name, 'goods_id' => (int) $sku->goods_id, 'sku_id' => (int) $sku->sku_id, 'price' => (string) $sku->price]);
