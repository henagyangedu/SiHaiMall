<?php

namespace App\Services\Admin;

use App\Models\Brand;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

class BrandService
{
    public function __construct(private TranslationService $trans) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = Brand::query()
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->whereIn('id', DB::table('brand_lang')->where('name', 'like', $like)->select('brand_id'));
            })
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->orderBy('sort')->orderBy('id')
            ->paginate($size);

        $langs = $this->trans->fetch('brand_lang', 'brand_id', collect($paginator->items())->pluck('id')->all(), ['name']);

        $paginator->through(function (Brand $b) use ($langs) {
            $names = $langs[$b->id]['name'] ?? [];

            return [
                'id'         => (int) $b->id,
                'logo'       => $b->logo,
                'sort'       => (int) $b->sort,
                'status'     => (int) $b->status,
                'name'       => $names,
                'name_text'  => $this->trans->resolve($names),
                'created_at' => $b->created_at?->format('Y-m-d H:i:s'),
            ];
        });

        return Paginate::format($paginator);
    }

    /** 启用的品牌下拉（商品表单用） */
    public function options(): array
    {
        $rows = Brand::query()->where('status', 1)->orderBy('sort')->orderBy('id')->get(['id']);
        $langs = $this->trans->fetch('brand_lang', 'brand_id', $rows->pluck('id')->all(), ['name']);

        return $rows->map(fn ($b) => [
            'id'   => (int) $b->id,
            'name' => $this->trans->resolve($langs[$b->id]['name'] ?? []),
        ])->all();
    }

    public function create(array $d): Brand
    {
        $name = (array) ($d['name'] ?? []);
        $this->trans->assertDefaultFilled($name, '品牌名称');

        return DB::transaction(function () use ($d, $name) {
            $brand = Brand::query()->create([
                'logo'   => (string) ($d['logo'] ?? ''),
                'sort'   => (int) ($d['sort'] ?? 0),
                'status' => (int) ($d['status'] ?? 1),
            ]);
            $this->trans->sync('brand_lang', 'brand_id', (int) $brand->id, ['name' => $name], ['name']);

            return $brand;
        });
    }

    public function update(int $id, array $d): Brand
    {
        $brand = $this->find($id);
        $name = (array) ($d['name'] ?? []);
        $this->trans->assertDefaultFilled($name, '品牌名称');

        return DB::transaction(function () use ($brand, $id, $d, $name) {
            $brand->update([
                'logo'   => (string) ($d['logo'] ?? ''),
                'sort'   => (int) ($d['sort'] ?? $brand->sort),
                'status' => (int) ($d['status'] ?? $brand->status),
            ]);
            $this->trans->sync('brand_lang', 'brand_id', $id, ['name' => $name], ['name']);

            return $brand;
        });
    }

    public function setStatus(int $id, int $status): void
    {
        $this->find($id)->update(['status' => $status]);
    }

    public function delete(int $id): void
    {
        $brand = $this->find($id);
        if (DB::table('goods')->where('brand_id', $id)->whereNull('deleted_at')->exists()) {
            throw new BusinessException('该品牌下还有商品，不能删除');
        }

        $brand->delete();
    }

    private function find(int $id): Brand
    {
        return Brand::query()->find($id) ?? throw new BusinessException('品牌不存在');
    }
}
