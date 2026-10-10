<?php

namespace App\Services\Api;

use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 商品收藏。一个会员对一个商品最多收藏一次，重复收藏、取消没收藏过的都当作成功（接口幂等）。
 * 收藏之后商品下架或删除了，记录还在，列表里标成“已失效”，买家可以自己移除。
 */
class FavoriteService
{
    public function __construct(private CatalogService $catalog) {}

    public function has(int $memberId, int $goodsId): bool
    {
        return DB::table('member_favorite')->where('member_id', $memberId)->where('goods_id', $goodsId)->exists();
    }

    public function add(int $memberId, int $goodsId): void
    {
        if (!DB::table('goods')->where('id', $goodsId)->whereNull('deleted_at')->where('is_on_sale', 1)->exists()) {
            throw new BusinessException('商品不存在或已下架');
        }
        // 已经收藏过就什么都不做（唯一索引保证同时点两次也只有一条）
        DB::table('member_favorite')->insertOrIgnore(['member_id' => $memberId, 'goods_id' => $goodsId, 'created_at' => now()]);
    }

    public function remove(int $memberId, int $goodsId): void
    {
        DB::table('member_favorite')->where('member_id', $memberId)->where('goods_id', $goodsId)->delete();
    }

    /** 我的收藏，最近收藏的在前。在售的商品带当前价格；失效的只有名称和图片，invalid=true */
    public function list(int $memberId, int $page, int $size, string $lang, string $currency, ?array $region): array
    {
        $p = DB::table('member_favorite')->where('member_id', $memberId)->orderByDesc('id')
            ->paginate(min(max($size, 1), 50), ['goods_id', 'created_at'], 'page', max($page, 1));
        $ids = collect($p->items())->pluck('goods_id')->map(fn ($v) => (int) $v)->all();

        $onSale = $ids === [] ? [] : collect($this->catalog->goodsList(['ids' => $ids, 'size' => 50], $lang, $currency, $region)['list'])->keyBy('id')->all();
        $gone = array_values(array_diff($ids, array_keys($onSale)));
        $goneRows = $gone === [] ? collect() : DB::table('goods')->whereIn('id', $gone)->get(['id', 'cover'])->keyBy('id');
        $goneTitles = $gone === [] ? collect() : DB::table('goods_lang')->whereIn('goods_id', $gone)->whereIn('lang', [$lang, 'zh-CN'])->get(['goods_id', 'lang', 'title'])->groupBy('goods_id');

        $p->through(function ($r) use ($onSale, $goneRows, $goneTitles, $lang) {
            $id = (int) $r->goods_id;
            if (isset($onSale[$id])) {
                return $onSale[$id] + ['invalid' => false];
            }
            $titles = collect($goneTitles->get($id, []))->pluck('title', 'lang');

            return ['id' => $id, 'title' => (string) ($titles[$lang] ?? $titles->first() ?? ''), 'cover' => (string) ($goneRows[$id]->cover ?? ''), 'invalid' => true];
        });

        return Paginate::format($p);
    }
}
