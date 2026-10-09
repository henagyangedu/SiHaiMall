<?php

namespace App\Services\Admin;

use App\Services\Common\TranslationService;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

class StockLogService
{
    private const TYPE_TEXT = [1 => '下单扣减', 2 => '取消回补', 3 => '售后回补', 4 => '后台调整', 5 => '导入'];

    public function __construct(private TranslationService $trans) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = DB::table('stock_log as l')
            ->leftJoin('goods_sku as s', 's.id', '=', 'l.sku_id')
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(function ($w) use ($like) {
                    $w->whereIn('l.goods_id', DB::table('goods_lang')->where('title', 'like', $like)->select('goods_id'))
                      ->orWhereIn('l.goods_id', DB::table('goods')->where('goods_sn', 'like', $like)->select('id'))
                      ->orWhere('s.sku_sn', 'like', $like);
                });
            })
            ->when(!empty($f['goods_id']), fn ($q) => $q->where('l.goods_id', (int) $f['goods_id']))
            ->when(!empty($f['type']), fn ($q) => $q->where('l.type', (int) $f['type']))
            ->when(!empty($f['date_from']), fn ($q) => $q->where('l.created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('l.created_at', '<=', $f['date_to'] . ' 23:59:59'))
            ->orderByDesc('l.id')
            ->select('l.*', 's.sku_sn')
            ->paginate($size);

        $titles = $this->trans->fetch('goods_lang', 'goods_id', collect($paginator->items())->pluck('goods_id')->unique()->all(), ['title']);

        $paginator->through(fn ($r) => [
            'id'         => (int) $r->id,
            'goods_id'   => (int) $r->goods_id,
            'goods_title' => $this->trans->resolve($titles[$r->goods_id]['title'] ?? []),
            'sku_sn'     => (string) ($r->sku_sn ?? ''),
            'type'       => (int) $r->type,
            'type_text'  => self::TYPE_TEXT[(int) $r->type] ?? '其它',
            'change_qty' => (int) $r->change_qty,
            'after_qty'  => (int) $r->after_qty,
            'ref_no'     => $r->ref_no,
            'operator'   => $r->operator,
            'remark'     => $r->remark,
            'created_at' => $r->created_at,
        ]);

        return Paginate::format($paginator);
    }
}
