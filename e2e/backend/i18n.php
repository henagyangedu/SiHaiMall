<?php

use App\Models\Language;
use App\Services\Common\LocaleService;
use Illuminate\Support\Facades\DB;

/**
 * 界面语言点击测试的后端步骤（E2E_STEP）：
 * - 默认：保证语言表里有西班牙语、法语、德语、日语、俄语（没有就建，建的时候是禁用的），把它们临时启用，返回各自原来的状态
 * - restore：按 E2E_VALUE（{"es":0,…}）把状态恢复回去
 * 语言记录留在库里（禁用），想看效果到后台“设置 → 语言管理”里启用就行。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
$names = ['es' => 'Español', 'fr' => 'Français', 'de' => 'Deutsch', 'ja' => '日本語', 'ru' => 'Русский'];

if ((string) getenv('E2E_STEP') === 'restore') {
    foreach ((array) json_decode((string) getenv('E2E_VALUE'), true) as $code => $status) {
        if (isset($names[$code])) {
            Language::query()->where('code', $code)->where('is_default', 0)->update(['status' => (int) $status]);
        }
    }
    app(LocaleService::class)->flush();
    $out(['ok' => true]);

    return;
}

// 挑一个有货的在售商品，用来看详情页的按钮
$goodsId = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 1)->orderBy('g.id')->value('g.id');
if (!$goodsId) {
    $out(['error' => '找不到单规格、已上架、有库存的商品']);

    return;
}

$original = [];
$sort = (int) Language::query()->max('sort');
foreach ($names as $code => $name) {
    $row = Language::query()->where('code', $code)->first();
    if (!$row) {
        $row = Language::query()->create(['code' => $code, 'name' => $name, 'is_default' => 0, 'status' => 0, 'sort' => ++$sort]);
    }
    $original[$code] = (int) $row->status;
    if ((int) $row->status !== 1) {
        $row->update(['status' => 1]);
    }
    // 页面上按名称选语言，名称以库里的为准（管理员可能改过）
    $names[$code] = (string) $row->name;
}
app(LocaleService::class)->flush();

$out(['original' => $original, 'names' => $names, 'goods_id' => (int) $goodsId]);
