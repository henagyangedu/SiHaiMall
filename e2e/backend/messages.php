<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use App\Models\MessageTemplate;
use App\Services\Common\NotifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 站内信点击测试的后端步骤，用环境变量 E2E_MSG_STEP 选：
 *  - prepare：清掉测试会员原有的消息，再通过通知服务给他发三条（发货、到货提醒、提现打款），给一个临时管理员令牌；
 *             记下“提现打款”场景站内信开关原来的值；
 *  - restore：把“提现打款”的站内信开关恢复成 prepare 时记下的值（E2E_MSG_SITE，'none' = 原来没有这条记录）。
 * 发出去的消息不清理，留在测试会员的消息中心里当例子。
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
$siteRow = fn () => MessageTemplate::query()->where('scene', 'withdraw_paid')->where('channel', MessageTemplate::CHANNEL_SITE);

if (getenv('E2E_MSG_STEP') === 'restore') {
    $was = (string) getenv('E2E_MSG_SITE');
    $was === 'none' ? $siteRow()->delete() : $siteRow()->update(['is_enabled' => (int) $was]);
    $out(['ok' => true]);

    return;
}

$order = DB::table('order')->where('member_id', $member->id)->orderByDesc('id')->first(['order_no']);
$goods = DB::table('goods')->where('is_on_sale', 1)->whereNull('deleted_at')->orderBy('id')->first(['id']);
if (!$order || !$goods) {
    $out(['error' => '测试会员没有订单，或者开发库里没有在售商品']);

    return;
}
// 这三个场景的站内信如果被关了，这次测试就发不出消息
$off = MessageTemplate::query()->where('channel', MessageTemplate::CHANNEL_SITE)->where('is_enabled', 0)
    ->whereIn('scene', ['order_shipped', 'goods_restock', 'withdraw_paid'])->pluck('scene')->all();
if ($off) {
    $out(['error' => '这些场景的站内信在后台被关掉了，先打开再跑：' . implode('、', $off)]);

    return;
}
$was = $siteRow()->value('is_enabled');

DB::table('member_message')->where('member_id', $member->id)->delete();
$notify = app(NotifyService::class);
$title = (string) DB::table('goods_lang')->where('goods_id', $goods->id)->where('lang', 'zh-CN')->value('title');
$notify->deliver('withdraw_paid', (int) $member->id, ['withdraw_no' => 'WD' . date('ymdHis') . '0001', 'amount' => '120.00']);
$notify->deliver('goods_restock', (int) $member->id, ['goods_title' => $title, 'spec' => '-', 'goods_id' => (int) $goods->id]);
$notify->deliver('order_shipped', (int) $member->id, ['order_no' => $order->order_no, 'express_name' => '顺丰速运', 'express_no' => 'SF1234567890']);

$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out([
    'order_no' => $order->order_no, 'goods_id' => (int) $goods->id, 'admin_token' => $token,
    'count' => DB::table('member_message')->where('member_id', $member->id)->count(), 'site_was' => $was === null ? 'none' : (string) (int) $was,
]);
