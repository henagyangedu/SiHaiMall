<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 商品的规格参数和配套商品：后台保存、买家端展示（多语言、下架的不显示、“适用设备”反向显示） */
class GoodsRelatedParamsTest extends FeatureTestCase
{
    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoryId = (int) DB::table('category')->insertGetId(['pid' => 0, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** 后台“新增商品”表单提交的内容（单规格） */
    private function payload(string $title, array $over = []): array
    {
        return $over + [
            'category_id' => $this->categoryId, 'spec_type' => 1, 'title' => ['zh-CN' => $title, 'en' => $title.' EN'], 'images' => ['/uploads/t.png'], 'is_on_sale' => 1,
            'sku' => ['price' => '100.00', 'stock' => 10],
        ];
    }

    private function createGoods(string $title, array $over = []): int
    {
        $r = $this->adminApi('POST', '/goods', $this->payload($title, $over));
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));

        return (int) $r->json('data.id');
    }

    public function test_params_are_saved_shown_in_the_buyers_language_and_replaced_on_edit(): void
    {
        $params = [
            ['name' => ['zh-CN' => '粮桶容量', 'en' => 'Hopper capacity'], 'value' => ['zh-CN' => '5 升', 'en' => '5 L']],
            ['name' => ['zh-CN' => '供电方式'], 'value' => ['zh-CN' => '5V 适配器 + 电池备用']],   // 没填英文
            ['name' => ['zh-CN' => ''], 'value' => ['zh-CN' => '']],                                  // 空行会被丢掉
        ];
        $id = $this->createGoods('可视喂食器', ['params' => $params]);

        // 后台读回来：两行，带各语言的原文
        $admin = $this->adminApi('GET', "/goods/{$id}")->json('data.params');
        $this->assertCount(2, $admin);
        $this->assertSame(['Hopper capacity', '5 L'], [$admin[0]['name']['en'], $admin[0]['value']['en']]);

        // 买家端：中文原样；英文没填的那行回退到默认语言
        $this->assertSame([['name' => '粮桶容量', 'value' => '5 升'], ['name' => '供电方式', 'value' => '5V 适配器 + 电池备用']], $this->api('GET', "/goods/{$id}")->json('data.params'));
        $en = $this->api('GET', "/goods/{$id}", [], null, ['X-Lang' => 'en'])->json('data.params');
        $this->assertSame([['name' => 'Hopper capacity', 'value' => '5 L'], ['name' => '供电方式', 'value' => '5V 适配器 + 电池备用']], $en);

        // 编辑是整体覆盖，顺序按提交的来；请求里不带 params 就不动原来的
        $this->adminApi('PUT', "/goods/{$id}", $this->payload('可视喂食器', ['params' => [
            ['name' => ['zh-CN' => '重量'], 'value' => ['zh-CN' => '2.6 kg']], $params[0],
        ]]))->assertJsonPath('code', 0);
        $this->assertSame(['重量', '粮桶容量'], array_column($this->api('GET', "/goods/{$id}")->json('data.params'), 'name'));
        $this->adminApi('PUT', "/goods/{$id}", $this->payload('可视喂食器'))->assertJsonPath('code', 0);
        $this->assertCount(2, $this->api('GET', "/goods/{$id}")->json('data.params'));
        $this->adminApi('PUT', "/goods/{$id}", $this->payload('可视喂食器', ['params' => []]))->assertJsonPath('code', 0);
        $this->assertSame([], $this->api('GET', "/goods/{$id}")->json('data.params'));
        $this->assertSame(0, DB::table('goods_param_lang')->count(), '删掉参数时翻译也一起删');

        // 只填了名称或只填了值、超过 30 行都被拒绝
        $this->assertNotSame(0, $this->adminApi('PUT', "/goods/{$id}", $this->payload('可视喂食器', ['params' => [['name' => ['zh-CN' => '只有名称'], 'value' => ['zh-CN' => '']]]]))->json('code'));
        $this->assertNotSame(0, $this->adminApi('PUT', "/goods/{$id}", $this->payload('可视喂食器', ['params' => [['name' => ['en' => 'English only'], 'value' => ['en' => 'x']]]]))->json('code'));
        $many = array_fill(0, 31, ['name' => ['zh-CN' => '参数'], 'value' => ['zh-CN' => '值']]);
        $this->assertNotSame(0, $this->adminApi('PUT', "/goods/{$id}", $this->payload('可视喂食器', ['params' => $many]))->json('code'));
    }

    public function test_related_goods_show_on_the_device_and_the_device_shows_on_the_accessory(): void
    {
        $filter = $this->createGoods('替换滤芯');
        $bag = $this->createGoods('集便袋');
        $offSale = $this->createGoods('已下架的配件', ['is_on_sale' => 0]);
        $device = $this->createGoods('智能饮水机', ['related_ids' => [$bag, $filter, $offSale]]);
        $other = $this->createGoods('另一台饮水机', ['related_ids' => [$filter]]);

        // 后台读回来：保持提交的顺序，带名称给选择框显示
        $admin = $this->adminApi('GET', "/goods/{$device}")->json('data');
        $this->assertSame([$bag, $filter, $offSale], $admin['related_ids']);
        $this->assertSame('集便袋', $admin['related'][0]['title']);

        // 设备页：配套商品按顺序显示，带价格；下架的不出现
        $detail = $this->api('GET', "/goods/{$device}")->json('data');
        $this->assertSame([$bag, $filter], array_column($detail['related'], 'id'));
        $this->assertSame(['集便袋', '100.00'], [$detail['related'][0]['title'], $detail['related'][0]['price']]);
        $this->assertSame([], $detail['fits']);

        // 配件页：自动显示“适用设备”（两台都把它设成了配套），自己没有配套商品
        $acc = $this->api('GET', "/goods/{$filter}")->json('data');
        $this->assertEqualsCanonicalizing([$device, $other], array_column($acc['fits'], 'id'));
        $this->assertSame([], $acc['related']);
        $this->assertSame('智能饮水机 EN', collect($this->api('GET', "/goods/{$filter}", [], null, ['X-Lang' => 'en'])->json('data.fits'))->firstWhere('id', $device)['title']);

        // 改配套：整体覆盖
        $this->adminApi('PUT', "/goods/{$device}", $this->payload('智能饮水机', ['related_ids' => [$filter]]))->assertJsonPath('code', 0);
        $this->assertSame([$filter], array_column($this->api('GET', "/goods/{$device}")->json('data.related'), 'id'));
        $this->assertSame([], $this->api('GET', "/goods/{$bag}")->json('data.fits'));

        // 不能设自己、不能设不存在的、最多 12 个
        $this->assertSame('不能把商品自己设为配套商品', $this->adminApi('PUT', "/goods/{$device}", $this->payload('智能饮水机', ['related_ids' => [$device]]))->json('msg'));
        $this->assertNotSame(0, $this->adminApi('PUT', "/goods/{$device}", $this->payload('智能饮水机', ['related_ids' => [999999]]))->json('code'));
        $this->assertNotSame(0, $this->adminApi('PUT', "/goods/{$device}", $this->payload('智能饮水机', ['related_ids' => range(1000, 1012)]))->json('code'));
        $this->assertSame([$filter], array_column($this->api('GET', "/goods/{$device}")->json('data.related'), 'id'), '被拒绝的修改不会改掉原来的配套');

        // 配件被删除后，设备页不再显示它
        $this->adminApi('DELETE', "/goods/{$filter}")->assertJsonPath('code', 0);
        $this->assertSame([], $this->api('GET', "/goods/{$device}")->json('data.related'));
    }
}
