<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Bundle;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 搭配套餐点击测试的后端步骤：
 * 挑两个单规格、在售、有库存的商品（优先挑“设备 + 它的配套商品”这样的一对），删掉以前跑这套流程留下的示例套餐，给一个临时管理员令牌。
 * 套餐本身是在后台页面上建的，跑完留着（启用状态）当例子；流程里下的那张订单会被取消。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
if (!$member || !DB::table('member_address')->where('member_id', $member->id)->exists()) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT') . '，或者他还没有收货地址']);

    return;
}

// 单规格、在售、库存不少于 5 的商品，带上它唯一的那个规格
$usable = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 5)
    ->orderBy('g.id')->get(['g.id as goods_id', 'k.id as sku_id', 'k.price'])->keyBy('goods_id');
// 优先：一个设备和它的一个配套商品
$pair = null;
foreach (DB::table('goods_relation')->orderBy('goods_id')->orderBy('sort')->get(['goods_id', 'related_goods_id']) as $r) {
    if ($usable->has($r->goods_id) && $usable->has($r->related_goods_id)) {
        $pair = [$usable[$r->goods_id], $usable[$r->related_goods_id]];
        break;
    }
}
$pair ??= $usable->count() >= 2 ? $usable->values()->slice(0, 2)->all() : null;
if (!$pair) {
    $out(['error' => '找不到两个单规格、已上架、库存不少于 5 的商品']);

    return;
}

// 以前跑这套流程留下的示例套餐：删掉，免得一样的套餐越积越多
Bundle::query()->where('name', 'like', '示例套餐：%')->get()->each->delete();

$title = fn (int $goodsId) => (string) DB::table('goods_lang')->where('goods_id', $goodsId)->where('lang', 'zh-CN')->value('title');
$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out([
    'admin_token' => $token,
    'a' => ['goods_id' => (int) $pair[0]->goods_id, 'sku_id' => (int) $pair[0]->sku_id, 'price' => (string) $pair[0]->price, 'title' => $title((int) $pair[0]->goods_id)],
    'b' => ['goods_id' => (int) $pair[1]->goods_id, 'sku_id' => (int) $pair[1]->sku_id, 'price' => (string) $pair[1]->price, 'title' => $title((int) $pair[1]->goods_id)],
]);
