<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\GoodsSku;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** 汇率自动更新：只动设为自动的币种、加价、变动过大不更新、行情源出错时汇率不变（行情源是模拟的） */
class RateSyncTest extends FeatureTestCase
{
    private function usd(): Currency
    {
        return Currency::query()->where('code', 'USD')->firstOrFail();
    }

    private function rate(string $code): string
    {
        return rtrim(rtrim((string) Currency::query()->where('code', $code)->value('rate'), '0'), '.');
    }

    public function test_only_auto_currencies_follow_the_market_with_markup(): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(['amount' => 1.0, 'base' => 'CNY', 'date' => '2026-10-09', 'rates' => ['USD' => 0.1425, 'EUR' => 0.13335]])]);
        $eur = $this->adminApi('POST', '/currency', ['code' => 'EUR', 'name' => '欧元', 'symbol' => '€', 'rate' => '0.13'])->json('data.id');

        // 没有设为自动的币种：不请求行情，后台点“立即更新”给出说明
        $this->artisan('mall:rate-sync')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('没有设为自动更新的币种', $this->adminApi('POST', '/currency/rate-sync')->json('msg'));

        // 基准币种不能设为自动；美元设为自动，欧元保持手动
        $base = Currency::query()->where('is_base', 1)->value('id');
        $this->assertNotSame(0, $this->adminApi('PUT', "/currency/{$base}/rate-mode", ['mode' => 2])->json('code'));
        $this->adminApi('PUT', "/currency/{$this->usd()->id}/rate-mode", ['mode' => 2])->assertJsonPath('code', 0);

        $r = $this->adminApi('POST', '/currency/rate-sync');
        $r->assertJsonPath('code', 0);
        $this->assertSame([1, 'USD', '0.1425', '0.14', '0.1425', 'updated'], [
            $r->json('data.updated'), $r->json('data.items.0.code'), $r->json('data.items.0.market'), $r->json('data.items.0.old'), $r->json('data.items.0.rate'), $r->json('data.items.0.status'),
        ]);
        $this->assertSame(['0.1425', '0.13'], [$this->rate('USD'), $this->rate('EUR')], '手动的欧元不动');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'base=CNY') && str_contains($req->url(), 'symbols=USD'));

        // 买家看到的价格马上跟着变：100 元的商品 14.00 → 14.25 美元
        $sku = $this->makeSku('100.00', 10);
        $goodsId = GoodsSku::query()->whereKey($sku)->value('goods_id');
        $this->assertSame('14.25', (string) $this->api('GET', "/goods/{$goodsId}", [], null, ['X-Currency' => 'USD'])->json('data.skus.0.price'));

        // 加价 2%：0.1425 × 1.02 = 0.14535；再跑一遍没有变化
        $this->adminApi('PUT', '/currency/rate-config', ['rate_source' => 'frankfurter', 'rate_markup' => '2', 'rate_max_change' => 10])->assertJsonPath('code', 0);
        $this->artisan('mall:rate-sync')->assertSuccessful();
        $this->assertSame('0.14535', $this->rate('USD'));
        $this->assertSame('unchanged', $this->adminApi('POST', '/currency/rate-sync')->json('data.items.0.status'));

        // 设置页读得到设置和最近一次的结果
        $cfg = $this->adminApi('GET', '/currency/rate-config');
        $this->assertSame(['frankfurter', '2', 10, true, '2026-10-09', 'USD'], [
            $cfg->json('data.rate_source'), $cfg->json('data.rate_markup'), $cfg->json('data.rate_max_change'),
            $cfg->json('data.state.ok'), $cfg->json('data.state.date'), $cfg->json('data.state.items.0.code'),
        ]);
        $this->assertCount(2, $cfg->json('data.sources'));
        $this->assertNotNull($eur);
    }

    public function test_large_swings_and_source_failures_leave_rates_untouched(): void
    {
        $this->usd()->update(['rate_mode' => 2]);
        $this->adminApi('PUT', '/currency/rate-config', ['rate_source' => 'erapi', 'rate_markup' => '0', 'rate_max_change' => 10])->assertJsonPath('code', 0);

        // 行情和现在的汇率差了一倍多：不更新，结果里说明原因和行情给的值
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'time_last_update_unix' => 1791590551, 'base_code' => 'CNY', 'rates' => ['CNY' => 1, 'USD' => 0.3]])]);
        $r = $this->adminApi('POST', '/currency/rate-sync');
        $this->assertSame([0, 'skipped', '0.3'], [$r->json('data.updated'), $r->json('data.items.0.status'), $r->json('data.items.0.rate_new')]);
        $this->assertStringContainsString('超过 10%', $r->json('data.items.0.msg'));
        $this->assertSame('0.14', $this->rate('USD'));

        // 管理员确认后手工改一次，自动更新接着走（0.29 → 0.3 在 10% 以内）
        $this->adminApi('PUT', "/currency/{$this->usd()->id}/rate", ['rate' => '0.29'])->assertJsonPath('code', 0);
        $this->assertSame(1, $this->adminApi('POST', '/currency/rate-sync')->json('data.updated'));
        $this->assertSame('0.3', $this->rate('USD'));
    }

    public function test_source_errors_keep_rates_and_are_recorded(): void
    {
        $this->usd()->update(['rate_mode' => 2]);

        Http::fake(['api.frankfurter.dev/*' => Http::response(['message' => 'not found'], 404)]);
        $r = $this->adminApi('POST', '/currency/rate-sync');
        $this->assertNotSame(0, $r->json('code'));
        $this->assertStringContainsString('请换一个行情源', $r->json('msg'));
        $this->assertSame('0.14', $this->rate('USD'));
        $state = $this->adminApi('GET', '/currency/rate-config')->json('data.state');
        $this->assertFalse($state['ok']);

        // 定时任务里出错不抛异常，返回失败
        $this->artisan('mall:rate-sync')->assertFailed();

        // 返回里没有这个币种、或者给了 0：跳过
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['CNY' => 1, 'USD' => 0]])]);
        $this->adminApi('PUT', '/currency/rate-config', ['rate_source' => 'erapi', 'rate_markup' => '0', 'rate_max_change' => 10]);
        $r = $this->adminApi('POST', '/currency/rate-sync');
        $this->assertSame(['skipped', '这个行情源没有该币种的汇率'], [$r->json('data.items.0.status'), $r->json('data.items.0.msg')]);
        $this->assertSame('0.14', $this->rate('USD'));

        // 设置的校验：加价最多 20%，行情源只能选列出的
        $this->assertNotSame(0, $this->adminApi('PUT', '/currency/rate-config', ['rate_source' => 'erapi', 'rate_markup' => '21', 'rate_max_change' => 10])->json('code'));
        $this->assertNotSame(0, $this->adminApi('PUT', '/currency/rate-config', ['rate_source' => 'other', 'rate_markup' => '1', 'rate_max_change' => 10])->json('code'));
    }
}
