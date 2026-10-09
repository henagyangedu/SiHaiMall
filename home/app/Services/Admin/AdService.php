<?php

namespace App\Services\Admin;

use App\Models\Ad;
use App\Models\AdPosition;
use App\Services\Common\LocaleService;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 广告位与广告（首页轮播图就是“首页轮播”广告位下的广告）。
 * 图片和标题按语言分别设置；某语言没传图时前台用默认语言的图。
 * 对外可见 = 广告位启用、广告启用、当前时间在起止时间内（留空表示不限）。
 */
class AdService
{
    public function __construct(private TranslationService $trans, private LocaleService $locale) {}

    // ------------------------------------------------------------------ 广告位
    public function positions(): array
    {
        $rows = AdPosition::query()->orderBy('id')->get();
        $counts = DB::table('ad')->whereNull('deleted_at')->groupBy('position_id')->selectRaw('position_id, count(*) as c')->pluck('c', 'position_id');

        return $rows->map(fn (AdPosition $p) => [
            'id' => (int) $p->id, 'code' => $p->code, 'name' => $p->name, 'remark' => $p->remark, 'status' => (int) $p->status,
            'built_in' => in_array($p->code, AdPosition::BUILT_IN, true), 'ad_count' => (int) ($counts[$p->id] ?? 0),
        ])->all();
    }

    public function savePosition(int $id, array $d): AdPosition
    {
        $code = strtolower(trim((string) $d['code']));
        $p = $id > 0 ? $this->findPosition($id) : new AdPosition();

        if ($id > 0 && in_array($p->code, AdPosition::BUILT_IN, true) && $code !== $p->code) {
            throw new BusinessException('预置广告位的编码不能修改');
        }
        if (AdPosition::query()->where('code', $code)->when($id > 0, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            throw new BusinessException('广告位编码已存在：' . $code);
        }
        $p->fill(['code' => $code, 'name' => trim((string) $d['name']), 'remark' => trim((string) ($d['remark'] ?? '')), 'status' => (int) ($d['status'] ?? 1)])->save();

        return $p;
    }

    public function deletePosition(int $id): void
    {
        $p = $this->findPosition($id);
        if (in_array($p->code, AdPosition::BUILT_IN, true)) {
            throw new BusinessException('预置广告位不能删除，可以停用');
        }
        if (Ad::query()->where('position_id', $id)->exists()) {
            throw new BusinessException('该广告位下还有广告，请先删除广告');
        }
        $p->delete();
    }

    // ------------------------------------------------------------------ 广告
    public function ads(int $positionId): array
    {
        $this->findPosition($positionId);
        $rows = Ad::query()->where('position_id', $positionId)->orderBy('sort')->orderBy('id')->get();
        $texts = $this->trans->fetch('ad_lang', 'ad_id', $rows->pluck('id')->all(), ['title', 'image']);

        return $rows->map(fn (Ad $a) => [
            'id' => (int) $a->id, 'position_id' => (int) $a->position_id,
            'title' => $texts[$a->id]['title'] ?? [], 'image' => $texts[$a->id]['image'] ?? [],
            'title_text' => $this->trans->resolve($texts[$a->id]['title'] ?? []), 'image_url' => $this->trans->resolve($texts[$a->id]['image'] ?? []),
            'link_type' => $a->link_type, 'link_type_text' => Ad::LINK_TYPES[$a->link_type] ?? $a->link_type, 'link_value' => $a->link_value,
            'sort' => (int) $a->sort, 'status' => (int) $a->status,
            'start_at' => $a->start_at?->format('Y-m-d H:i:s'), 'end_at' => $a->end_at?->format('Y-m-d H:i:s'),
            'showing' => (int) $a->status === 1 && (!$a->start_at || $a->start_at->isPast()) && (!$a->end_at || $a->end_at->isFuture()),
        ])->all();
    }

    public function saveAd(int $id, array $d): Ad
    {
        $this->findPosition((int) $d['position_id']);
        $this->trans->assertDefaultFilled((array) ($d['image'] ?? []), '图片');
        [$linkType, $linkValue] = $this->cleanLink((string) ($d['link_type'] ?? 'none'), trim((string) ($d['link_value'] ?? '')));
        if (!empty($d['start_at']) && !empty($d['end_at']) && strtotime((string) $d['end_at']) <= strtotime((string) $d['start_at'])) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        return DB::transaction(function () use ($id, $d, $linkType, $linkValue) {
            $a = $id > 0 ? $this->findAd($id) : new Ad();
            $a->fill([
                'position_id' => (int) $d['position_id'], 'link_type' => $linkType, 'link_value' => $linkValue,
                'sort' => (int) ($d['sort'] ?? 0), 'status' => (int) ($d['status'] ?? 1),
                'start_at' => !empty($d['start_at']) ? $d['start_at'] : null, 'end_at' => !empty($d['end_at']) ? $d['end_at'] : null,
            ])->save();

            $data = [];
            foreach ($this->locale->codes() as $code) {
                $data['title'][$code] = (string) ($d['title'][$code] ?? '');
                $data['image'][$code] = (string) ($d['image'][$code] ?? '');
            }
            $this->trans->sync('ad_lang', 'ad_id', (int) $a->id, $data, ['title', 'image']);

            return $a;
        });
    }

    public function setAdStatus(int $id, int $status): void
    {
        $this->findAd($id)->update(['status' => $status]);
    }

    public function deleteAd(int $id): void
    {
        $this->findAd($id)->delete();
    }

    /** 跳转目标校验：商品、分类、文章必须存在；自定义链接只允许 http(s) 或站内路径 @return array{0:string,1:string} */
    private function cleanLink(string $type, string $value): array
    {
        if (!isset(Ad::LINK_TYPES[$type])) {
            throw new BusinessException('跳转类型不正确');
        }
        if ($type === 'none') {
            return ['none', ''];
        }
        if ($type === 'url') {
            if (!preg_match('#^(https?://|/)[^\s]+$#i', $value)) {
                throw new BusinessException('自定义链接必须以 http://、https:// 或 / 开头');
            }

            return [$type, $value];
        }

        $table = ['goods' => 'goods', 'category' => 'category', 'article' => 'article'][$type];
        if (!ctype_digit($value) || !DB::table($table)->where('id', (int) $value)->whereNull('deleted_at')->exists()) {
            throw new BusinessException('跳转的' . Ad::LINK_TYPES[$type] . '不存在，请填写正确的 ID');
        }

        return [$type, (string) (int) $value];
    }

    private function findPosition(int $id): AdPosition
    {
        return AdPosition::query()->find($id) ?? throw new BusinessException('广告位不存在');
    }

    private function findAd(int $id): Ad
    {
        return Ad::query()->find($id) ?? throw new BusinessException('广告不存在');
    }
}
