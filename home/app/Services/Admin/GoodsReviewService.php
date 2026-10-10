<?php

namespace App\Services\Admin;

use App\Models\GoodsReview;
use App\Services\Common\GoodsReviewService as ReviewCache;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** 后台的商品评价管理：查看、隐藏 / 恢复显示、商家回复（原评价和追评各回复一次）。评价内容本身不能改 */
class GoodsReviewService
{
    public function __construct(private LocaleService $locale, private ReviewCache $cache) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));
        $lang = $this->locale->defaultLang();

        $p = DB::table('goods_review as r')
            ->leftJoin('member as m', 'm.id', '=', 'r.member_id')
            ->leftJoin('order as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('goods_lang as gl', fn ($j) => $j->on('gl.goods_id', '=', 'r.goods_id')->where('gl.lang', $lang))
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('r.content', 'like', $like)->orWhere('r.append_content', 'like', $like)->orWhere('gl.title', 'like', $like)->orWhere('o.order_no', $kw));
            })
            ->when(($f['rating'] ?? '') !== '' && $f['rating'] !== null, fn ($q) => $q->where('r.rating', (int) $f['rating']))
            ->when(($f['status'] ?? '') !== '' && $f['status'] !== null, fn ($q) => $q->where('r.status', (int) $f['status']))
            ->when(($f['replied'] ?? '') !== '' && $f['replied'] !== null, fn ($q) => (int) $f['replied'] === 1 ? $q->where('r.reply', '!=', '') : $q->where('r.reply', ''))
            ->when(!empty($f['goods_id']), fn ($q) => $q->where('r.goods_id', (int) $f['goods_id']))
            ->orderByDesc('r.id')
            ->select('r.*', 'm.nickname', 'm.account', 'o.order_no', 'gl.title as goods_title')
            ->paginate($size);

        $p->through(fn ($r) => [
            'id' => (int) $r->id, 'goods_id' => (int) $r->goods_id, 'goods_title' => (string) ($r->goods_title ?? ''), 'spec_text' => (string) $r->spec_text,
            'order_no' => (string) ($r->order_no ?? ''), 'member_id' => (int) $r->member_id,
            'member' => ($r->nickname ?: $r->account) ?: ('#' . $r->member_id),
            'rating' => (int) $r->rating, 'content' => (string) $r->content, 'images' => json_decode((string) ($r->images ?: '[]'), true) ?: [],
            'is_anonymous' => (int) $r->is_anonymous, 'status' => (int) $r->status,
            'reply' => (string) $r->reply, 'replied_at' => $r->replied_at ? (string) $r->replied_at : null, 'created_at' => (string) $r->created_at,
            'append' => ReviewCache::appendOf($r),
        ]);

        return Paginate::format($p);
    }

    /** 隐藏或恢复显示 */
    public function setStatus(int $id, int $status): void
    {
        $r = $this->find($id);
        $r->update(['status' => $status === GoodsReview::SHOWN ? GoodsReview::SHOWN : GoodsReview::HIDDEN]);
        $this->cache->forgetSummary((int) $r->goods_id);
    }

    /** 商家回复；传空串表示删除回复。$toAppend 为 true 时回复的是追评（和原评价的回复分开存） */
    public function reply(int $id, string $text, bool $toAppend = false): void
    {
        $text = trim($text);
        $r = $this->find($id);
        if ($toAppend) {
            if (!$r->append_at) {
                throw new BusinessException('这条评价没有追评');
            }
            $r->update(['append_reply' => $text, 'append_replied_at' => $text === '' ? null : now()]);

            return;
        }
        $r->update(['reply' => $text, 'replied_at' => $text === '' ? null : now()]);
    }

    private function find(int $id): GoodsReview
    {
        return GoodsReview::query()->find($id) ?? throw new BusinessException('评价不存在');
    }
}
