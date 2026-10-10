<?php

namespace App\Services\Common;

use App\Models\Order;
use App\Models\OrderInvoice;
use App\Models\OrderRefund;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;

/**
 * 订单发票的规则（买家申请、修改、撤销，以及“这张订单能不能开、开多少”）。后台的开票、驳回、作废在 Admin\OrderInvoiceService。
 *
 * - 交易设置里开了“允许申请发票”才能申请；只有基准币种（人民币）的订单能开；
 * - 订单要已付款且没有全部退完；开票金额 = 实付 − 已退完成的金额；
 * - 一张订单一张发票：待开票、被驳回、已作废时买家可以改了重新提交（回到待开票），已开出的不能改；
 * - 待开票、被驳回的可以撤销（删掉记录，之后可以重新申请）。
 */
class OrderInvoiceService
{
    public function __construct(private ConfigService $config, private LocaleService $locale) {}

    public function enabled(): bool
    {
        return (int) $this->config->value('trade', 'invoice_enabled') === 1;
    }

    /** 可开票金额：实付减去已经退完成的 */
    public function amount(Order $o): string
    {
        $refunded = (string) OrderRefund::query()->where('order_id', $o->id)->where('status', OrderRefund::DONE)->sum('refund_amount');
        $left = bcsub((string) $o->pay_amount, $refunded === '' ? '0' : $refunded, 2);

        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    /** 这张订单现在为什么不能申请发票；能申请时返回空串 */
    public function blockReason(Order $o): string
    {
        if (!$this->enabled()) {
            return '暂不支持申请发票';
        }
        if (strtoupper((string) $o->currency) !== strtoupper($this->locale->baseCurrency())) {
            return '该币种的订单暂不支持开具发票';
        }
        if (!in_array((int) $o->pay_status, [OrderStatus::PAY_PAID, OrderStatus::PAY_PART_REFUND], true)) {
            return (int) $o->pay_status === OrderStatus::PAY_REFUNDED ? '订单已全额退款，不能开具发票' : '订单付款后才能申请发票';
        }
        if (bccomp($this->amount($o), '0', 2) <= 0) {
            return '订单没有可开票的金额';
        }

        return '';
    }

    /** 订单详情里用的概要：能不能申请、现在是什么状态（没申请过是 null） */
    public function brief(Order $o): array
    {
        $inv = OrderInvoice::query()->where('order_id', $o->id)->first(['status']);

        return [
            'can_apply' => $this->blockReason($o) === '' && (!$inv || (int) $inv->status !== OrderInvoice::ISSUED),
            'status' => $inv ? (int) $inv->status : null,
        ];
    }

    /** 申请页用的完整信息 */
    public function info(Order $o, string $lang): array
    {
        $inv = OrderInvoice::query()->where('order_id', $o->id)->first();
        $reason = $this->blockReason($o);
        // 上次在别的订单上填过的抬头，申请时自动带出
        $last = $inv ? null : OrderInvoice::query()->where('member_id', $o->member_id)->orderByDesc('id')->first();

        return [
            'can_apply' => $reason === '' && (!$inv || (int) $inv->status !== OrderInvoice::ISSUED),
            'reason' => $reason,
            'amount' => $this->amount($o), 'currency' => (string) $o->currency,
            'notice' => (string) $this->config->value('trade', 'invoice_notice', $lang),
            'invoice' => $inv ? $this->format($inv) : null,
            'last' => $last ? array_intersect_key($this->format($last), array_flip(['title_type', 'title', 'tax_no', 'email', 'company_info'])) : null,
        ];
    }

    /** 申请，或者在开出之前修改（被驳回、已作废的改完回到待开票） */
    public function apply(Order $o, array $d): array
    {
        if (($reason = $this->blockReason($o)) !== '') {
            throw new BusinessException($reason);
        }
        $type = (int) $d['title_type'];
        $taxNo = strtoupper(trim((string) ($d['tax_no'] ?? '')));
        if ($type === OrderInvoice::TYPE_COMPANY && !preg_match('/^[0-9A-Z]{15,20}$/', $taxNo)) {
            throw new BusinessException('请填写正确的纳税人识别号');
        }
        $company = [];
        foreach (OrderInvoice::COMPANY_FIELDS as $f) {
            $company[$f] = $type === OrderInvoice::TYPE_COMPANY ? trim((string) ($d['company_info'][$f] ?? '')) : '';
        }

        $inv = OrderInvoice::query()->where('order_id', $o->id)->first();
        if ($inv && (int) $inv->status === OrderInvoice::ISSUED) {
            throw new BusinessException('发票已开出，不能修改');
        }
        $data = [
            'order_no' => $o->order_no, 'member_id' => $o->member_id, 'title_type' => $type, 'title' => trim((string) $d['title']),
            'tax_no' => $type === OrderInvoice::TYPE_COMPANY ? $taxNo : '', 'email' => trim((string) $d['email']),
            'company_info' => array_filter($company, fn ($v) => $v !== '') ?: null,
            'amount' => $this->amount($o), 'status' => OrderInvoice::PENDING,
            'invoice_no' => '', 'file_url' => '', 'reject_reason' => '', 'admin_id' => 0, 'issued_at' => null,
        ];
        try {
            $inv = OrderInvoice::query()->updateOrCreate(['order_id' => $o->id], $data);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // 连点两次同时提交：第二次按“已经申请过”处理
            throw new BusinessException('已经申请过发票了，请刷新后查看');
        }

        return $this->format($inv);
    }

    /** 撤销：只有还没开出的可以 */
    public function cancel(Order $o): void
    {
        $n = OrderInvoice::query()->where('order_id', $o->id)->whereIn('status', [OrderInvoice::PENDING, OrderInvoice::REJECTED])->delete();
        if ($n === 0) {
            throw new BusinessException(OrderInvoice::query()->where('order_id', $o->id)->exists() ? '发票已开出，不能撤销' : '还没有申请发票');
        }
    }

    public function format(OrderInvoice $i): array
    {
        return [
            'title_type' => (int) $i->title_type, 'title' => (string) $i->title, 'tax_no' => (string) $i->tax_no, 'email' => (string) $i->email,
            'company_info' => (array) ($i->company_info ?? []) + array_fill_keys(OrderInvoice::COMPANY_FIELDS, ''),
            'amount' => (string) $i->amount, 'status' => (int) $i->status,
            'invoice_no' => (string) $i->invoice_no, 'file_url' => (int) $i->status === OrderInvoice::ISSUED ? (string) $i->file_url : '',
            'reject_reason' => (string) $i->reject_reason,
            'created_at' => $i->created_at?->format('Y-m-d H:i:s'), 'issued_at' => $i->issued_at?->format('Y-m-d H:i:s'),
        ];
    }
}
