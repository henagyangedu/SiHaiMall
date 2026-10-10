<?php

namespace App\Services\Common;

use App\Models\GoodsReview;
use App\Models\Order;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use App\Support\Paginate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 商品评价（买家提交、商品页展示；后台的管理在 Admin\GoodsReviewService）。
 *
 * - 订单“已完成”后才能评价；每个商品行评价一次，提交后不能修改；已经全部退款的商品行不能评价。
 * - 提交后直接显示，后台事后可以隐藏。
 * - 追评：评价过的商品行可以再追加一次文字和图片（没有期限），提交后不能改；星级不变，不影响好评率。追评和原评价存在同一行。
 * - 评价内容显示原文，不翻译。匿名评价不显示头像，昵称只留第一个字。
 * - 评价数、好评率从评价表统计，按商品缓存 10 分钟；评价新增、隐藏 / 恢复时清掉。
 */
class GoodsReviewService
{
    public const MAX_IMAGES = 6;

    public function __construct(private UploadService $uploads) {}

    /** 订单里哪些商品行已经评价过。@return int[] 商品行 ID */
    public function reviewedItemIds(int $orderId): array
    {
        return GoodsReview::query()->where('order_id', $orderId)->pluck('order_item_id')->map(fn ($v) => (int) $v)->all();
    }

    /** 订单里哪些商品行还能追评（评价过、还没追过）。@return int[] 商品行 ID */
    public function appendableItemIds(int $orderId): array
    {
        return GoodsReview::query()->where('order_id', $orderId)->whereNull('append_at')->pluck('order_item_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function orderCanReview(Order $order): bool
    {
        return (int) $order->status === OrderStatus::FINISHED;
    }

    /** @param array{order_item_id:int,rating:int,content?:?string,images?:?array,is_anonymous?:mixed} $d */
    public function create(Order $order, array $d): array
    {
        if (!self::orderCanReview($order)) {
            throw new BusinessException('订单完成后才能评价');
        }
        $item = DB::table('order_item')->where('order_id', $order->id)->where('id', (int) $d['order_item_id'])->first()
            ?? throw new BusinessException('订单里没有这件商品');
        if ((int) $item->refund_qty >= (int) $item->qty) {
            throw new BusinessException('这件商品已经全部退款，不能评价');
        }
        $images = array_values(array_unique(array_filter(array_map('strval', (array) ($d['images'] ?? [])))));
        if (count($images) > self::MAX_IMAGES) {
            throw new BusinessException('评价图片最多 ' . self::MAX_IMAGES . ' 张');
        }
        // 只接受本人通过上传接口传的图片
        $this->uploads->assertMemberImages((int) $order->member_id, $images);

        try {
            $review = GoodsReview::query()->create([
                'goods_id' => $item->goods_id, 'sku_id' => $item->sku_id, 'order_id' => $order->id, 'order_item_id' => $item->id,
                'member_id' => $order->member_id, 'rating' => (int) $d['rating'], 'content' => trim((string) ($d['content'] ?? '')),
                'images' => $images, 'spec_text' => (string) $item->spec_text, 'is_anonymous' => empty($d['is_anonymous']) ? 0 : 1,
                'status' => GoodsReview::SHOWN,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // 同时点了两次，或者之前评价过
            throw new BusinessException('这件商品已经评价过了');
        }
        $this->forgetSummary((int) $item->goods_id);

        return ['id' => (int) $review->id];
    }

    /**
     * 追评：给自己评价过的商品行再追加一次。
     * @param array{order_item_id:int,content:string,images?:?array} $d
     */
    public function append(Order $order, array $d): void
    {
        $review = GoodsReview::query()->where('order_id', $order->id)->where('order_item_id', (int) $d['order_item_id'])->first()
            ?? throw new BusinessException('这件商品还没有评价，不能追评');
        $content = trim((string) $d['content']);
        if ($content === '') {
            throw new BusinessException('请填写追评内容');
        }
        $images = array_values(array_unique(array_filter(array_map('strval', (array) ($d['images'] ?? [])))));
        if (count($images) > self::MAX_IMAGES) {
            throw new BusinessException('评价图片最多 ' . self::MAX_IMAGES . ' 张');
        }
        $this->uploads->assertMemberImages((int) $order->member_id, $images);

        // 带条件更新：同时点了两次，只有一次生效
        $n = GoodsReview::query()->where('id', $review->id)->whereNull('append_at')
            ->update(['append_content' => $content, 'append_images' => json_encode($images, JSON_UNESCAPED_SLASHES), 'append_at' => now(), 'updated_at' => now()]);
        if ($n === 0) {
            throw new BusinessException('这件商品已经追评过了');
        }
        // “有图”的条数可能变了
        $this->forgetSummary((int) $review->goods_id);
    }

    /**
     * 商品页的评价列表（只含显示中的），新的在前。
     * @param string $level all 全部 / good 好评 / mid 中评 / bad 差评 / image 有图
     */
    public function byGoods(int $goodsId, string $level, int $page, int $size): array
    {
        $p = DB::table('goods_review as r')->leftJoin('member as m', 'm.id', '=', 'r.member_id')
            ->where('r.goods_id', $goodsId)->where('r.status', GoodsReview::SHOWN)
            ->when($level === 'good', fn ($q) => $q->where('r.rating', '>=', GoodsReview::GOOD_FROM))
            ->when($level === 'mid', fn ($q) => $q->where('r.rating', 3))
            ->when($level === 'bad', fn ($q) => $q->where('r.rating', '<=', 2))
            // 有图：原评价或追评带图都算
            ->when($level === 'image', fn ($q) => $q->where(fn ($w) => $w->where(fn ($a) => $a->whereNotNull('r.images')->where('r.images', '!=', '[]'))
                ->orWhere(fn ($a) => $a->whereNotNull('r.append_images')->where('r.append_images', '!=', '[]'))))
            ->orderByDesc('r.id')
            ->select('r.id', 'r.rating', 'r.content', 'r.images', 'r.spec_text', 'r.is_anonymous', 'r.reply', 'r.replied_at', 'r.created_at', 'm.nickname', 'm.avatar',
                'r.append_content', 'r.append_images', 'r.append_at', 'r.append_reply', 'r.append_replied_at')
            ->paginate(min(max($size, 1), 50), ['*'], 'page', max($page, 1));

        $p->through(fn ($r) => [
            'id' => (int) $r->id, 'rating' => (int) $r->rating, 'content' => (string) $r->content,
            'images' => json_decode((string) ($r->images ?: '[]'), true) ?: [], 'spec_text' => (string) $r->spec_text,
            'nickname' => (int) $r->is_anonymous === 1 ? self::maskName((string) $r->nickname) : (string) $r->nickname,
            'avatar' => (int) $r->is_anonymous === 1 ? '' : (string) $r->avatar,
            'reply' => (string) $r->reply, 'replied_at' => $r->replied_at ? date('Y-m-d H:i:s', strtotime((string) $r->replied_at)) : null,
            'created_at' => $r->created_at ? date('Y-m-d H:i:s', strtotime((string) $r->created_at)) : null,
            'append' => self::appendOf($r),
        ]);

        return Paginate::format($p);
    }

    /**
     * 商品的评价概况。
     * @return array{count:int,good_rate:int,avg:string,good:int,mid:int,bad:int,with_images:int} good_rate 是好评占比（百分数，取整），没有评价时为 100
     */
    public function summary(int $goodsId): array
    {
        return Cache::remember(self::summaryKey($goodsId), 600, function () use ($goodsId) {
            $r = DB::table('goods_review')->where('goods_id', $goodsId)->where('status', GoodsReview::SHOWN)->selectRaw(
                'COUNT(*) as c, COALESCE(SUM(rating), 0) as total, COALESCE(SUM(rating >= ?), 0) as good, COALESCE(SUM(rating = 3), 0) as mid, COALESCE(SUM(rating <= 2), 0) as bad, '
                . "COALESCE(SUM((images IS NOT NULL AND images != '[]') OR (append_images IS NOT NULL AND append_images != '[]')), 0) as img", [GoodsReview::GOOD_FROM]
            )->first();
            $count = (int) $r->c;

            return [
                'count' => $count, 'good_rate' => $count > 0 ? (int) round((int) $r->good * 100 / $count) : 100,
                'avg' => $count > 0 ? bcdiv((string) $r->total, (string) $count, 1) : '5.0',
                'good' => (int) $r->good, 'mid' => (int) $r->mid, 'bad' => (int) $r->bad, 'with_images' => (int) $r->img,
            ];
        });
    }

    public function forgetSummary(int $goodsId): void
    {
        Cache::forget(self::summaryKey($goodsId));
    }

    private static function summaryKey(int $goodsId): string
    {
        return 'goods_review_summary:' . $goodsId;
    }

    /**
     * 一行评价里的追评部分；没有追评返回 null。days 是追评距离首次评价过了几天（当天是 0）。
     * @param object $r 评价表的一行（查询构造器的结果）
     */
    public static function appendOf(object $r): ?array
    {
        if (empty($r->append_at)) {
            return null;
        }
        $at = strtotime((string) $r->append_at);
        $days = $r->created_at ? intdiv(max(0, strtotime(date('Y-m-d', $at)) - strtotime(date('Y-m-d', strtotime((string) $r->created_at)))), 86400) : 0;

        return [
            'content' => (string) $r->append_content, 'images' => json_decode((string) ($r->append_images ?: '[]'), true) ?: [],
            'created_at' => date('Y-m-d H:i:s', $at), 'days' => $days,
            'reply' => (string) $r->append_reply, 'replied_at' => $r->append_replied_at ? date('Y-m-d H:i:s', strtotime((string) $r->append_replied_at)) : null,
        ];
    }

    /** 匿名评价的昵称：只留第一个字 */
    private static function maskName(string $name): string
    {
        return ($name === '' ? '' : mb_substr($name, 0, 1)) . '***';
    }
}
