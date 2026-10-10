<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use App\Services\Common\StockSubscribeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 到货提醒点击测试的后端步骤，用环境变量 E2E_RESTOCK_STEP 选：
 *  - prepare：准备一个缺货的示例商品（货号 E2E-RESTOCK，没有就建一个，有就把库存改回 0），清掉测试会员在它上面的订阅，给一个临时管理员令牌；
 *  - arrive ：补货 5 件，并执行一次到货通知（定时任务做的那件事）；
 *  - leave  ：测试结束时调用——库存改回 0、测试会员重新订阅。这样开发库里留着一个“缺货、有 1 人在等”的例子，后台商品列表能看到。
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

const RESTOCK_SN = 'E2E-RESTOCK';
const RESTOCK_TITLE = '到货提醒示例：限量陶瓷慢食碗';

$goodsId = (int) DB::table('goods')->where('goods_sn', RESTOCK_SN)->whereNull('deleted_at')->value('id');
if (!$goodsId) {
    // 借一个在售商品的分类；图片用下面自己生成的占位图
    $from = DB::table('goods')->where('is_on_sale', 1)->whereNull('deleted_at')->orderBy('id')->first(['category_id']);
    if (!$from) {
        $out(['error' => '开发库里没有在售商品，建不了示例商品']);

        return;
    }
    $goodsId = (int) DB::table('goods')->insertGetId([
        'category_id' => $from->category_id, 'goods_sn' => RESTOCK_SN, 'cover' => '', 'images' => '[]', 'spec_type' => 1,
        'price' => '39.00', 'stock' => 0, 'is_on_sale' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('goods_lang')->insert([
        ['goods_id' => $goodsId, 'lang' => 'zh-CN', 'title' => RESTOCK_TITLE, 'subtitle' => '缺货时可以订阅到货提醒'],
        ['goods_id' => $goodsId, 'lang' => 'en', 'title' => 'Back-in-stock demo: limited ceramic slow feeder', 'subtitle' => 'Out of stock — set an alert'],
    ]);
    DB::table('goods_sku')->insert(['goods_id' => $goodsId, 'sku_sn' => RESTOCK_SN . '-1', 'price' => '39.00', 'stock' => 0, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
}
$skuId = (int) DB::table('goods_sku')->where('goods_id', $goodsId)->orderBy('id')->value('id');

// 示例商品自己的占位图（和演示数据的占位图一个风格：柔和底色 + 商品名），放在本地上传目录里
$dir = public_path('uploads/e2e');
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
if (!is_file($dir . '/restock.svg')) {
    file_put_contents($dir . '/restock.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800">'
        . '<rect width="800" height="800" fill="#EFE5D6"/><circle cx="560" cy="300" r="170" fill="#E2CDB9"/><circle cx="270" cy="520" r="110" fill="#C3CCB8"/>'
        . '<text x="400" y="390" text-anchor="middle" font-family="sans-serif" font-size="46" font-weight="700" fill="#6B5A4B">限量陶瓷慢食碗</text>'
        . '<text x="400" y="450" text-anchor="middle" font-family="sans-serif" font-size="28" fill="#8A7A6A">到货提醒示例</text></svg>');
}
$cover = rtrim((string) config('app.uploads_url') ?: rtrim((string) config('app.url'), '/') . '/uploads', '/') . '/e2e/restock.svg';
DB::table('goods')->where('id', $goodsId)->where('cover', '!=', $cover)->update(['cover' => $cover, 'images' => json_encode([$cover])]);
$setStock = function (int $n) use ($goodsId, $skuId): void {
    DB::table('goods_sku')->where('id', $skuId)->update(['stock' => $n, 'updated_at' => now()]);
    DB::table('goods')->where('id', $goodsId)->update(['stock' => $n, 'is_on_sale' => 1, 'updated_at' => now()]);
};
$service = app(StockSubscribeService::class);

switch (getenv('E2E_RESTOCK_STEP')) {
    case 'prepare':
        $setStock(0);
        $service->unsubscribe((int) $member->id, $skuId);
        $token = Str::random(64);
        AdminToken::query()->create([
            'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
            'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
        ]);
        $out(['goods_id' => $goodsId, 'sku_id' => $skuId, 'title' => RESTOCK_TITLE, 'admin_token' => $token]);
        break;
    case 'arrive':
        $setStock(5);
        [$done, $sent] = $service->notifyRestocked();
        $out(['done' => $done, 'sent' => $sent]);
        break;
    case 'leave':
        $setStock(0);
        $service->subscribe((int) $member->id, $skuId);
        $out(['ok' => true]);
        break;
    default:
        $out(['error' => '未知的步骤：' . getenv('E2E_RESTOCK_STEP')]);
}
