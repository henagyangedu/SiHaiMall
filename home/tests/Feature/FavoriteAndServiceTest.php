<?php

namespace Tests\Feature;

use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 商品收藏，以及基础信息里的客服入口配置 */
class FavoriteAndServiceTest extends FeatureTestCase
{
    private function goodsId(int $sku): int
    {
        return (int) DB::table('goods_sku')->where('id', $sku)->value('goods_id');
    }

    public function test_favourite_toggle_and_goods_detail_flag(): void
    {
        [, $token] = $this->makeMember();
        $goods = $this->goodsId($this->makeSku('100.00', 5));

        // 没登录：详情里是 false，不能收藏
        $this->api('GET', "/goods/{$goods}")->assertJsonPath('data.favorited', false);
        $this->api('POST', "/favorites/{$goods}")->assertJsonPath('code', 401);

        $this->api('GET', "/goods/{$goods}", [], $token)->assertJsonPath('data.favorited', false);
        $this->api('POST', "/favorites/{$goods}", [], $token)->assertJsonPath('code', 0)->assertJsonPath('msg', '已收藏');
        $this->api('GET', "/goods/{$goods}", [], $token)->assertJsonPath('data.favorited', true);

        // 重复收藏只有一条；取消两次也不报错
        $this->api('POST', "/favorites/{$goods}", [], $token)->assertJsonPath('code', 0);
        $this->assertSame(1, DB::table('member_favorite')->count());
        $this->api('DELETE', "/favorites/{$goods}", [], $token)->assertJsonPath('code', 0);
        $this->api('DELETE', "/favorites/{$goods}", [], $token)->assertJsonPath('code', 0);
        $this->api('GET', "/goods/{$goods}", [], $token)->assertJsonPath('data.favorited', false);

        // 不存在或下架的商品不能收藏
        $this->api('POST', '/favorites/999999', [], $token)->assertJsonPath('code', 1);
        DB::table('goods')->where('id', $goods)->update(['is_on_sale' => 0]);
        $this->api('POST', "/favorites/{$goods}", [], $token)->assertJsonPath('code', 1);
        $this->assertSame('Saved to your favourites', $this->api('POST', '/favorites/'.$this->goodsId($this->makeSku('1.00', 1)), [], $token, ['X-Lang' => 'en'])->json('msg'));
    }

    public function test_my_favourites_list(): void
    {
        [$a, $aToken] = $this->makeMember();
        [, $bToken] = $this->makeMember();
        $g1 = $this->goodsId($this->makeSku('100.00', 5));
        $g2 = $this->goodsId($this->makeSku('59.90', 5));
        $g3 = $this->goodsId($this->makeSku('10.00', 5));
        foreach ([$g1, $g2, $g3] as $g) {
            $this->api('POST', "/favorites/{$g}", [], $aToken)->assertJsonPath('code', 0);
        }
        $this->api('POST', "/favorites/{$g1}", [], $bToken)->assertJsonPath('code', 0);

        // 最近收藏的在前；带当前价格
        $list = $this->api('GET', '/favorites', [], $aToken);
        $list->assertJsonPath('code', 0)->assertJsonPath('data.total', 3);
        $this->assertSame([$g3, $g2, $g1], array_column($list->json('data.list'), 'id'));
        $this->assertSame(['59.90', false], [$list->json('data.list.1.price'), $list->json('data.list.1.invalid')]);

        // 商品下架后还在列表里，标成失效、不带价格；买家可以移除
        DB::table('goods')->where('id', $g2)->update(['is_on_sale' => 0]);
        $row = $this->api('GET', '/favorites', [], $aToken)->json('data.list.1');
        $this->assertSame([$g2, true], [$row['id'], $row['invalid']]);
        $this->assertNotSame('', $row['title']);
        $this->assertArrayNotHasKey('price', $row);
        $this->api('DELETE', "/favorites/{$g2}", [], $aToken)->assertJsonPath('code', 0);
        $this->assertSame([$g3, $g1], array_column($this->api('GET', '/favorites', [], $aToken)->json('data.list'), 'id'));

        // 各看各的；取消别人收藏过的商品只影响自己
        $this->assertSame([$g1], array_column($this->api('GET', '/favorites', [], $bToken)->json('data.list'), 'id'));
        $this->api('DELETE', "/favorites/{$g3}", [], $bToken)->assertJsonPath('code', 0);
        $this->assertSame(2, DB::table('member_favorite')->where('member_id', $a->id)->count());
        $this->api('GET', '/favorites')->assertJsonPath('code', 401);

        // 商品列表接口不接受外部传入的 ids 参数
        $this->assertSame(3, $this->api('GET', '/goods?ids[]='.$g1)->json('data.total') + 1, '下架了一个，剩 2 个在售');
    }

    public function test_customer_service_settings_are_saved_and_exposed_to_buyers(): void
    {
        $site = $this->api('GET', '/config/init')->json('data.site');
        $this->assertSame(['', ''], [$site['service_url'], $site['service_hours']]);

        $this->adminApi('PUT', '/config/base', [
            'service_url' => 'https://work.weixin.qq.com/kfid/kfc0000000000000000', 'service_phone' => '400-000-0000',
            'service_hours' => ['zh-CN' => '周一至周五 9:00-18:00', 'en' => 'Mon-Fri 9:00-18:00'],
        ])->assertJsonPath('code', 0);

        $zh = $this->api('GET', '/config/init')->json('data.site');
        $this->assertSame(['https://work.weixin.qq.com/kfid/kfc0000000000000000', '400-000-0000', '周一至周五 9:00-18:00'], [$zh['service_url'], $zh['service_phone'], $zh['service_hours']]);
        $this->assertSame('Mon-Fri 9:00-18:00', $this->api('GET', '/config/init', [], null, ['X-Lang' => 'en'])->json('data.site.service_hours'));

        // 客服链接必须是 https 地址：别的协议（包括能执行脚本的）不收
        foreach (['javascript:alert(1)', 'http://insecure.example/chat', 'work.weixin.qq.com/kf', 'https://'] as $bad) {
            $this->assertNotSame(0, $this->adminApi('PUT', '/config/base', ['service_url' => $bad])->json('code'), "不应该接受：{$bad}");
        }
        $this->assertSame('https://work.weixin.qq.com/kfid/kfc0000000000000000', app(ConfigService::class)->get('base')['service_url']);
        // 可以清空
        $this->adminApi('PUT', '/config/base', ['service_url' => ''])->assertJsonPath('code', 0);
    }
}
