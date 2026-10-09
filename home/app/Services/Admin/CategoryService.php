<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    /** 分类最多几级 */
    private const MAX_LEVEL = 3;

    public function __construct(private TranslationService $trans) {}

    /** 完整分类树（含禁用），name 为各语言文本，name_text 为默认语言显示名 */
    public function tree(): array
    {
        $rows = Category::query()->orderBy('sort')->orderBy('id')->get();
        $langs = $this->trans->fetch('category_lang', 'category_id', $rows->pluck('id')->all(), ['name']);

        $list = [];
        foreach ($rows as $c) {
            $names = $langs[$c->id]['name'] ?? [];
            $list[] = [
                'id'        => (int) $c->id,
                'pid'       => (int) $c->pid,
                'image'     => $c->image,
                'sort'      => (int) $c->sort,
                'status'    => (int) $c->status,
                'name'      => $names,
                'name_text' => $this->trans->resolve($names),
            ];
        }

        return $this->buildTree($list, 0);
    }

    public function create(array $d): Category
    {
        $name = (array) ($d['name'] ?? []);
        $this->trans->assertDefaultFilled($name, '分类名称');
        $pid = (int) ($d['pid'] ?? 0);
        $this->assertParent($pid, null);

        return DB::transaction(function () use ($d, $name, $pid) {
            $cat = Category::query()->create([
                'pid'    => $pid,
                'image'  => (string) ($d['image'] ?? ''),
                'sort'   => (int) ($d['sort'] ?? 0),
                'status' => (int) ($d['status'] ?? 1),
            ]);
            $this->trans->sync('category_lang', 'category_id', (int) $cat->id, ['name' => $name], ['name']);

            return $cat;
        });
    }

    public function update(int $id, array $d): Category
    {
        $cat = $this->find($id);
        $name = (array) ($d['name'] ?? []);
        $this->trans->assertDefaultFilled($name, '分类名称');
        $pid = (int) ($d['pid'] ?? 0);
        $this->assertParent($pid, $id);

        return DB::transaction(function () use ($cat, $id, $d, $name, $pid) {
            $cat->update([
                'pid'    => $pid,
                'image'  => (string) ($d['image'] ?? ''),
                'sort'   => (int) ($d['sort'] ?? $cat->sort),
                'status' => (int) ($d['status'] ?? $cat->status),
            ]);
            $this->trans->sync('category_lang', 'category_id', $id, ['name' => $name], ['name']);

            return $cat;
        });
    }

    public function setStatus(int $id, int $status): void
    {
        $this->find($id)->update(['status' => $status]);
    }

    public function delete(int $id): void
    {
        $cat = $this->find($id);
        if (Category::query()->where('pid', $id)->exists()) {
            throw new BusinessException('请先删除下级分类');
        }
        if (DB::table('goods')->where('category_id', $id)->whereNull('deleted_at')->exists()) {
            throw new BusinessException('该分类下还有商品，不能删除');
        }

        $cat->delete(); // 软删除，翻译记录保留
    }

    private function find(int $id): Category
    {
        return Category::query()->find($id) ?? throw new BusinessException('分类不存在');
    }

    /** 校验上级：存在、不成环、层级不超过上限（含被移动节点自身子树的深度） */
    private function assertParent(int $pid, ?int $selfId): void
    {
        $pidMap = Category::query()->pluck('pid', 'id')->map(fn ($v) => (int) $v)->all();

        if ($pid !== 0 && !isset($pidMap[$pid])) {
            throw new BusinessException('上级分类不存在');
        }

        if ($selfId !== null) {
            $cur = $pid;
            $guard = 0;
            while ($cur > 0 && $guard++ < 20) {
                if ($cur === $selfId) {
                    throw new BusinessException('上级分类不能是自己或自己的下级');
                }
                $cur = $pidMap[$cur] ?? 0;
            }
        }

        $parentLevel = 0;
        $cur = $pid;
        $guard = 0;
        while ($cur > 0 && $guard++ < 20) {
            $parentLevel++;
            $cur = $pidMap[$cur] ?? 0;
        }
        $subHeight = $selfId === null ? 0 : $this->subtreeHeight($selfId, $pidMap);

        if ($parentLevel + 1 + $subHeight > self::MAX_LEVEL) {
            throw new BusinessException('分类最多支持 ' . self::MAX_LEVEL . ' 级');
        }
    }

    /** 节点下方还有几层（没有下级为 0） */
    private function subtreeHeight(int $id, array $pidMap): int
    {
        $height = 0;
        foreach ($pidMap as $child => $parent) {
            if ($parent === $id) {
                $height = max($height, 1 + $this->subtreeHeight((int) $child, $pidMap));
            }
        }

        return $height;
    }

    private function buildTree(array $rows, int $pid): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['pid'] === $pid) {
                $r['children'] = $this->buildTree($rows, $r['id']);
                $out[] = $r;
            }
        }

        return $out;
    }
}
