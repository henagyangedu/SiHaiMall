<?php

namespace App\Services\Common;

use App\Services\Api\CatalogService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 到货提醒：买家在缺货的规格上订阅，补货后通知一次。
 *
 * 一个会员对一个规格最多一条记录（gm_stock_subscribe）。status 0 = 等待到货，1 = 已通知。
 * 不在改库存的地方触发，而是由定时任务 mall:restock-notify 扫描“等待中且规格已有库存”的记录：
 * 改库存的入口很多（后台改、保存规格、取消订单回补、售后回补），集中扫描不会漏，也不用每处都记得调。
 * 通知一次即止；之后再缺货，买家要重新订阅（重新订阅会把这条记录改回等待中）。
 */
class StockSubscribeService
{
    public const WAITING = 0;
    public const NOTIFIED = 1;

    /** 一个会员最多同时等多少个规格 */
    public const MAX_WAITING = 50;

    public function __construct(private CatalogService $catalog, private NotifyService $notify, private LocaleService $locale) {}

    /** 订阅：只有在售商品里库存为 0 的规格能订阅。重复订阅当作成功 */
    public function subscribe(int $memberId, int $skuId): void
    {
        $sku = DB::table('goods_sku as s')->join('goods as g', 'g.id', '=', 's.goods_id')
            ->where('s.id', $skuId)->where('s.status', 1)->whereNull('g.deleted_at')->where('g.is_on_sale', 1)
            ->first(['s.id', 's.goods_id', 's.stock']) ?? throw new BusinessException('商品不存在或已下架');
        if ((int) $sku->stock > 0) {
            throw new BusinessException('该商品有货，可以直接购买');
        }

        $where = ['member_id' => $memberId, 'sku_id' => $skuId];
        $waiting = DB::table('stock_subscribe')->where('member_id', $memberId)->where('status', self::WAITING);
        if (!(clone $waiting)->where('sku_id', $skuId)->exists() && $waiting->count() >= self::MAX_WAITING) {
            throw new BusinessException('到货提醒最多 ' . self::MAX_WAITING . ' 个，请先取消一些');
        }
        // 唯一索引保证同时点两次也只有一条；以前通知过的记录改回等待中
        DB::table('stock_subscribe')->insertOrIgnore($where + ['goods_id' => (int) $sku->goods_id, 'status' => self::WAITING, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_subscribe')->where($where)->where('status', self::NOTIFIED)
            ->update(['status' => self::WAITING, 'notified_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** 取消（也用来移除已通知的记录）；没订阅过也当作成功 */
    public function unsubscribe(int $memberId, int $skuId): void
    {
        DB::table('stock_subscribe')->where('member_id', $memberId)->where('sku_id', $skuId)->delete();
    }

    /**
     * 某个商品下，这位会员正在等待到货的规格。
     * @return int[]
     */
    public function waitingSkuIds(int $memberId, int $goodsId): array
    {
        return DB::table('stock_subscribe')->where('member_id', $memberId)->where('goods_id', $goodsId)->where('status', self::WAITING)
            ->orderBy('sku_id')->pluck('sku_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * 我的到货提醒，最近订阅的在前。
     * status：waiting 等待到货 / notified 已通知；in_stock：现在有没有货；invalid：商品或规格已下架、删除。
     */
    public function list(int $memberId, int $page, int $size, string $lang, string $currency, ?array $region): array
    {
        $p = DB::table('stock_subscribe')->where('member_id', $memberId)->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(min(max($size, 1), 50), ['goods_id', 'sku_id', 'status', 'notified_at', 'created_at'], 'page', max($page, 1));
        $briefs = $this->catalog->skuBriefs(collect($p->items())->pluck('sku_id')->map(fn ($v) => (int) $v)->all(), $lang, $currency, $region);
        $cur = app(PriceService::class)->currency($currency);

        $p->through(function ($r) use ($briefs, $cur) {
            $b = $briefs[(int) $r->sku_id] ?? null;
            $valid = $b !== null && $b['valid'];

            return [
                'sku_id' => (int) $r->sku_id, 'goods_id' => (int) $r->goods_id,
                'title' => (string) ($b['title'] ?? ''), 'spec_text' => (string) ($b['spec_text'] ?? ''), 'cover' => (string) ($b['image'] ?? ''),
                'price' => $valid ? $b['price'] : null, 'symbol' => $cur['symbol'],
                'status' => (int) $r->status === self::NOTIFIED ? 'notified' : 'waiting',
                'in_stock' => $valid && $b['stock'] > 0, 'invalid' => !$valid,
                'notified_at' => $r->notified_at, 'created_at' => $r->created_at,
            ];
        });

        return Paginate::format($p);
    }

    /**
     * 后台商品列表用：每个商品有多少人在等到货。
     * @param int[] $goodsIds
     * @return array<int,int> 商品 ID => 人数（没人等的商品不在里面）
     */
    public function waitingCounts(array $goodsIds): array
    {
        if ($goodsIds === []) {
            return [];
        }

        return DB::table('stock_subscribe')->whereIn('goods_id', $goodsIds)->where('status', self::WAITING)
            ->groupBy('goods_id')->selectRaw('goods_id, COUNT(DISTINCT member_id) AS n')->pluck('n', 'goods_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * 定时任务调用：给“等待中且规格已经有货”的订阅发到货通知。
     * 先把记录标成已通知再发信——发信失败（或者会员没有邮箱、通知场景没开）也不会反复重试，买家在“到货提醒”列表里能看到已到货。
     * @return array{0:int,1:int} [处理了多少条, 其中实际发出通知多少条]
     */
    public function notifyRestocked(int $limit = 500): array
    {
        $rows = DB::table('stock_subscribe as b')
            ->join('goods_sku as s', 's.id', '=', 'b.sku_id')->join('goods as g', 'g.id', '=', 's.goods_id')
            ->where('b.status', self::WAITING)->where('s.stock', '>', 0)->where('s.status', 1)
            ->whereNull('g.deleted_at')->where('g.is_on_sale', 1)
            ->orderBy('b.id')->limit($limit)->get(['b.id', 'b.member_id', 'b.sku_id']);

        $langs = DB::table('member')->whereIn('id', $rows->pluck('member_id')->unique()->all())->pluck('lang', 'id');
        $codes = $this->locale->codes();
        $done = $sent = 0;
        foreach ($rows as $r) {
            // 带上状态条件：两个进程同时跑时只有一个能改成功，不会发两次
            if (!DB::table('stock_subscribe')->where('id', $r->id)->where('status', self::WAITING)->update(['status' => self::NOTIFIED, 'notified_at' => now(), 'updated_at' => now()])) {
                continue;
            }
            $done++;
            try {
                // 商品名称和规格按会员自己的语言
                $lang = in_array($langs[$r->member_id] ?? '', $codes, true) ? (string) $langs[$r->member_id] : $this->locale->defaultLang();
                $b = $this->catalog->skuBriefs([(int) $r->sku_id], $lang, $this->locale->baseCurrency())[(int) $r->sku_id] ?? null;
                if ($b && $this->notify->deliver('goods_restock', (int) $r->member_id, ['goods_title' => $b['title'], 'spec' => $b['spec_text'] !== '' ? $b['spec_text'] : '-'])) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('到货通知发送失败', ['subscribe_id' => $r->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            }
        }

        return [$done, $sent];
    }
}
