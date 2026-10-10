<?php

namespace Tests\Feature;

use App\Services\Api\CatalogService;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 商品搜索（多个关键词）和后台配置的热门搜索词 */
class GoodsSearchTest extends FeatureTestCase
{
    private function goods(string $title, string $subtitle = '', string $keywords = ''): int
    {
        $id = (int) DB::table('goods_sku')->where('id', $this->makeSku('100.00', 5))->value('goods_id');
        DB::table('goods_lang')->where('goods_id', $id)->where('lang', 'zh-CN')->update(['title' => $title, 'subtitle' => $subtitle, 'keywords' => $keywords]);

        return $id;
    }

    private function search(string $keyword): array
    {
        return array_column($this->api('GET', '/goods?keyword='.urlencode($keyword))->json('data.list'), 'id');
    }

    public function test_every_word_must_match_title_subtitle_or_keywords(): void
    {
        $feeder = $this->goods('智能喂食器 5 升', '带摄像头，远程看饭');
        $fountain = $this->goods('智能饮水机', '静音循环', '猫咪,喝水');
        $bowl = $this->goods('陶瓷食盆');

        $this->assertEqualsCanonicalizing([$feeder, $fountain], $this->search('智能'));
        // 两个词都要命中，顺序无所谓；副标题和搜索关键词也算
        $this->assertSame([$feeder], $this->search('摄像头 智能'));
        $this->assertSame([$fountain], $this->search('智能　喝水'), '全角空格也是分隔');
        $this->assertSame([], $this->search('智能 食盆'));
        $this->assertSame([$bowl], $this->search('  食盆  '));
        // 通配符按普通字符处理，不会把所有商品都搜出来
        $this->assertSame([], $this->search('%'));
        $this->assertSame([], $this->search('_'));

        // 下架的搜不到
        DB::table('goods')->where('id', $bowl)->update(['is_on_sale' => 0]);
        $this->assertSame([], $this->search('食盆'));
    }

    public function test_search_terms_are_deduplicated_and_capped(): void
    {
        $this->assertSame([], CatalogService::searchTerms("  \u{3000} "));
        $this->assertSame(['a', 'b'], CatalogService::searchTerms('a b a'));
        $this->assertCount(5, CatalogService::searchTerms('1 2 3 4 5 6 7'));
        $this->assertSame(50, mb_strlen(CatalogService::searchTerms(str_repeat('长', 80))[0]));
    }

    public function test_hot_words_are_split_and_follow_buyer_language(): void
    {
        $this->assertSame([], $this->api('GET', '/config/init')->json('data.site.hot_words'));

        $this->adminApi('PUT', '/config/base', [
            'search_hot_words' => ['zh-CN' => '喂食器，饮水机, 猫砂盆、喂食器；', 'en' => ''],
        ])->assertJsonPath('code', 0);

        $this->assertSame(['喂食器', '饮水机', '猫砂盆'], $this->api('GET', '/config/init')->json('data.site.hot_words'));
        // 英文没填：用默认语言的
        $this->assertSame(['喂食器', '饮水机', '猫砂盆'], $this->api('GET', '/config/init', [], null, ['X-Lang' => 'en'])->json('data.site.hot_words'));

        $this->adminApi('PUT', '/config/base', ['search_hot_words' => ['zh-CN' => '喂食器', 'en' => 'feeder, fountain']])->assertJsonPath('code', 0);
        $this->assertSame(['feeder', 'fountain'], $this->api('GET', '/config/init', [], null, ['X-Lang' => 'en'])->json('data.site.hot_words'));

        // 最多 10 个，每个最多 20 个字
        $words = ConfigService::splitWords(implode(',', array_map(fn ($i) => "词{$i}", range(1, 15))).','.str_repeat('长', 30));
        $this->assertCount(10, $words);
        $this->assertSame(20, mb_strlen(ConfigService::splitWords(str_repeat('长', 30))[0]));
    }
}
