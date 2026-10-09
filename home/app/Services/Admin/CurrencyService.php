<?php

namespace App\Services\Admin;

use App\Models\Currency;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 币种与汇率。约定：商品价格以基准币种存储；rate 表示 1 个基准币种 = rate 个本币种。
 */
class CurrencyService
{
    public function __construct(private LocaleService $locale) {}

    public function list(): array
    {
        return Currency::query()->orderBy('sort')->orderBy('id')->get()->toArray();
    }

    public function create(array $d): Currency
    {
        if (Currency::query()->where('code', $d['code'])->exists()) {
            throw new BusinessException('币种代码已存在');
        }
        $first = !Currency::query()->exists();

        $cur = Currency::query()->create([
            'code'     => $d['code'],
            'name'     => $d['name'],
            'symbol'   => (string) ($d['symbol'] ?? ''),
            'rate'     => $first ? '1' : (string) $d['rate'],
            'decimals' => (int) ($d['decimals'] ?? 2),
            'is_base'  => $first ? 1 : 0,
            'rate_mode' => 1,
            'status'   => $first ? 1 : (int) ($d['status'] ?? 1),
            'sort'     => (int) ($d['sort'] ?? 0),
        ]);
        $this->locale->flush();

        return $cur;
    }

    /** 编辑基础信息（汇率走 updateRate，基准币种走 setBase） */
    public function update(int $id, array $d): Currency
    {
        $cur = $this->find($id);
        $status = array_key_exists('status', $d) ? (int) $d['status'] : (int) $cur->status;
        if ((int) $cur->is_base === 1 && $status !== 1) {
            throw new BusinessException('基准币种不能禁用');
        }

        $cur->update([
            'name'     => $d['name'],
            'symbol'   => (string) ($d['symbol'] ?? ''),
            'decimals' => (int) ($d['decimals'] ?? $cur->decimals),
            'status'   => $status,
            'sort'     => (int) ($d['sort'] ?? $cur->sort),
        ]);
        $this->locale->flush();

        return $cur;
    }

    public function updateRate(int $id, string $rate): void
    {
        $cur = $this->find($id);
        if ((int) $cur->is_base === 1) {
            throw new BusinessException('基准币种的汇率固定为 1，不能修改');
        }

        $cur->update(['rate' => $rate]);
        $this->locale->flush();
    }

    /** 更换基准币种：只允许在还没有商品、订单数据时操作；其它币种汇率按新基准重新换算 */
    public function setBase(int $id): void
    {
        $cur = $this->find($id);
        if ((int) $cur->is_base === 1) {
            return;
        }
        if ((int) $cur->status !== 1) {
            throw new BusinessException('请先启用该币种');
        }
        if (DB::table('goods_sku')->exists() || DB::table('goods_price')->exists() || DB::table('order')->exists()) {
            throw new BusinessException('已有商品或订单数据，不能更换基准币种');
        }

        DB::transaction(function () use ($cur) {
            $newBaseRate = (string) $cur->rate;
            foreach (Currency::query()->get() as $c) {
                if ((int) $c->id === (int) $cur->id) {
                    continue;
                }
                $c->update(['is_base' => 0, 'rate' => $this->div((string) $c->rate, $newBaseRate)]);
            }
            $cur->update(['is_base' => 1, 'rate' => '1']);
        });
        $this->locale->flush();
    }

    public function delete(int $id): void
    {
        $cur = $this->find($id);
        if ((int) $cur->is_base === 1) {
            throw new BusinessException('基准币种不能删除');
        }
        if (DB::table('order')->where('currency', $cur->code)->exists()
            || DB::table('goods_price')->where('currency', $cur->code)->exists()) {
            throw new BusinessException('该币种已有订单或商品定价数据，不能删除，请改为禁用');
        }

        $cur->delete();
        $this->locale->flush();
    }

    private function find(int $id): Currency
    {
        return Currency::query()->find($id) ?? throw new BusinessException('币种不存在');
    }

    private function div(string $a, string $b): string
    {
        return function_exists('bcdiv') ? bcdiv($a, $b, 8) : number_format((float) $a / (float) $b, 8, '.', '');
    }
}
