<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderInvoice;
use App\Services\Common\NotifyService;
use App\Services\Common\OrderInvoiceService as InvoiceRules;
use App\Services\Common\UploadService;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use App\Support\Paginate;

/**
 * 后台的发票管理：列表、开票（登记发票号码和文件）、驳回、作废。
 * 商城不对接税控或电子发票平台——发票是商家在自己的开票软件里开的，这里只做登记和把文件交给买家。
 * 状态流转都用带条件的 UPDATE：待开票 → 已开票 / 已驳回；已开票 → 已作废。
 */
class OrderInvoiceService
{
    public function __construct(private InvoiceRules $rules, private UploadService $uploads, private NotifyService $notify) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));
        $like = '%' . addcslashes($kw, '%_\\') . '%';

        $p = OrderInvoice::query()
            ->when(($f['status'] ?? '') !== '' && $f['status'] !== null, fn ($q) => $q->where('status', (int) $f['status']))
            ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w->where('order_no', 'like', $like)->orWhere('title', 'like', $like)->orWhere('invoice_no', 'like', $like)))
            ->orderByDesc('id')->paginate($size);

        $orders = Order::query()->whereIn('id', collect($p->items())->pluck('order_id')->all())->get()->keyBy('id');
        $p->through(function (OrderInvoice $i) use ($orders) {
            $o = $orders->get($i->order_id);

            // 文件地址在后台总是给出（买家端只在“已开票”时给，作废后就看不到了）
            return ['id' => (int) $i->id, 'order_id' => (int) $i->order_id, 'order_no' => (string) $i->order_no, 'member_id' => (int) $i->member_id, 'file_url' => (string) $i->file_url]
                + $this->rules->format($i)
                + ['pay_amount' => $o ? (string) $o->pay_amount : '0.00', 'invoiceable' => $o ? $this->rules->amount($o) : '0.00', 'pay_status' => $o ? (int) $o->pay_status : 0];
        });

        return Paginate::format($p);
    }

    /** 开票：登记发票号码、文件和实际开票金额 */
    public function issue(int $id, array $d, Admin $op): void
    {
        $inv = $this->find($id);
        $order = Order::query()->find($inv->order_id) ?? throw new BusinessException('订单不存在');
        $max = $this->rules->amount($order);
        $amount = Money::round((string) ($d['amount'] ?? $max), 2);
        if (bccomp($amount, '0', 2) <= 0) {
            throw new BusinessException('开票金额必须大于 0');
        }
        if (bccomp($amount, $max, 2) > 0) {
            throw new BusinessException("开票金额不能超过订单实付减去已退款的金额（{$max}）");
        }
        $url = trim((string) $d['file_url']);
        $this->uploads->assertAdminFile($url, UploadService::SCENE_INVOICE);

        $this->move($inv, OrderInvoice::PENDING, [
            'status' => OrderInvoice::ISSUED, 'invoice_no' => trim((string) $d['invoice_no']), 'file_url' => $url,
            'amount' => $amount, 'reject_reason' => '', 'admin_id' => (int) $op->id, 'issued_at' => now(),
        ], '只有待开票的申请可以开票');

        $this->notify->send('invoice_issued', (int) $inv->member_id, [
            'order_no' => (string) $inv->order_no, 'invoice_no' => trim((string) $d['invoice_no']), 'amount' => $amount, 'title' => (string) $inv->title,
        ]);
    }

    public function reject(int $id, string $reason, Admin $op): void
    {
        $inv = $this->find($id);
        $this->move($inv, OrderInvoice::PENDING, ['status' => OrderInvoice::REJECTED, 'reject_reason' => $reason, 'admin_id' => (int) $op->id], '只有待开票的申请可以驳回');

        $this->notify->send('invoice_rejected', (int) $inv->member_id, ['order_no' => (string) $inv->order_no, 'reject_reason' => $reason]);
    }

    /** 作废已开出的发票（比如订单后来全额退款了）。作废后买家可以重新申请 */
    public function void(int $id, string $reason, Admin $op): void
    {
        $this->move($this->find($id), OrderInvoice::ISSUED, ['status' => OrderInvoice::VOIDED, 'reject_reason' => $reason, 'admin_id' => (int) $op->id], '只有已开票的发票可以作废');
    }

    private function find(int $id): OrderInvoice
    {
        return OrderInvoice::query()->find($id) ?? throw new BusinessException('发票申请不存在');
    }

    /** 带条件的状态流转：状态已经被别人改了（买家刚撤销、另一个管理员刚处理）就报错，不覆盖 */
    private function move(OrderInvoice $inv, int $from, array $data, string $error): void
    {
        $n = OrderInvoice::query()->where('id', $inv->id)->where('status', $from)->update($data + ['updated_at' => now()]);
        if ($n === 0) {
            throw new BusinessException($error);
        }
    }
}
