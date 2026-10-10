<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use App\Models\TaxRate;
use App\Services\Api\AddressService;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 税费点击测试的后端步骤（E2E_STEP）：
 * - 默认：找出测试会员结算时会用的收货地址（国家、省份）、挑一个单规格在售商品、给一个临时管理员令牌，
 *   并把“按收货地区收税”的开关关掉（返回原来的值）。这个地区已经有别人配的税率规则时返回 skip，不去动它。
 * - switch：把开关设为 E2E_VALUE
 * - restore：删掉测试建的规则（税名是固定的“E2E 测试税”），开关恢复成 E2E_VALUE
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
const E2E_TAX_NAME = 'E2E 测试税';
$config = app(ConfigService::class);
$step = (string) getenv('E2E_STEP');

if ($step === 'switch') {
    $config->save('trade', ['tax_enabled' => (int) getenv('E2E_VALUE')]);
    $out(['ok' => true]);

    return;
}
if ($step === 'restore') {
    TaxRate::query()->where('name', E2E_TAX_NAME)->delete();
    $config->save('trade', ['tax_enabled' => (int) getenv('E2E_VALUE')]);
    $out(['ok' => true]);

    return;
}

$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
$address = $member ? (app(AddressService::class)->list((int) $member->id)[0] ?? null) : null;
if (!$address) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT') . '，或者他还没有收货地址']);

    return;
}
if (strtoupper((string) $address->country_code) !== 'CN' || (string) $address->province === '') {
    $out(['error' => '测试会员的默认收货地址要是国内的（这套流程在后台按省份建税率规则）']);

    return;
}
// 上次没跑完留下的规则先删掉；这个地区有别的规则就不动
TaxRate::query()->where('name', E2E_TAX_NAME)->delete();
$taken = TaxRate::query()->where('country_code', 'CN')->whereIn('province', ['', (string) $address->province])->exists();

$sku = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->where('g.is_virtual', 0)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 5)
    ->orderBy('g.id')->first(['g.id as goods_id', 'k.id as sku_id']);
if (!$sku) {
    $out(['error' => '找不到单规格、已上架、库存不少于 5 的商品']);

    return;
}

$original = (int) $config->value('trade', 'tax_enabled');
if (!$taken) {
    $config->save('trade', ['tax_enabled' => 0]);
}
$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out([
    'skip' => $taken, 'admin_token' => $token, 'enabled_original' => $original, 'province' => (string) $address->province,
    'sku_id' => (int) $sku->sku_id, 'name' => E2E_TAX_NAME,
]);
