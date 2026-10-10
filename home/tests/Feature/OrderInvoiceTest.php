<?php

namespace Tests\Feature;

use App\Drivers\Contracts\StorageDriver;
use App\Drivers\DriverManager;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\ConfigService;
use App\Services\Common\OrderRefundService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\FeatureTestCase;

/** 订单发票：买家付款后申请，商家登记发票号码和文件；开出前能改能撤，开出后不能动 */
class OrderInvoiceTest extends FeatureTestCase
{
    private const COMPANY = ['title_type' => 2, 'title' => '杭州某某科技有限公司', 'tax_no' => '91330100ma2abcde1x', 'email' => 'fin@example.com', 'company_info' => ['bank' => '招商银行杭州分行', 'bank_account' => '5719000011112222']];

    private const PERSONAL = ['title_type' => 1, 'title' => '张三', 'email' => 'zhang@example.com'];

    private string $token;

    private int $memberId;

    private int $addressId;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();
        [$m, $this->token] = $this->makeMember();
        $this->memberId = (int) $m->id;
        $this->addressId = (int) $this->makeAddress($this->memberId)->id;
        $this->sku = $this->makeSku('100.00', 50);

        // 假的存储驱动：不往磁盘写文件
        $fake = new class implements StorageDriver
        {
            public function code(): string
            {
                return 'local';
            }

            public function putFile(string $path, UploadedFile $file): void {}

            public function put(string $path, string $contents): void {}

            public function exists(string $path): bool
            {
                return true;
            }

            public function delete(string $path): void {}

            public function url(string $path): string
            {
                return 'https://cdn.test/uploads/'.ltrim($path, '/');
            }
        };
        $manager = Mockery::mock(DriverManager::class)->makePartial();
        $manager->shouldReceive('storage')->andReturn($fake);
        $this->app->instance(DriverManager::class, $manager);
    }

    private function enable(): void
    {
        app(ConfigService::class)->save('trade', ['invoice_enabled' => 1, 'invoice_notice' => ['zh-CN' => '电子发票在 3 个工作日内开出', 'en' => 'Issued within 3 business days']]);
    }

    private function paidOrder(int $qty = 2, array $headers = []): Order
    {
        return $this->pay($this->placeOrder($this->token, $this->sku, $qty, $this->addressId, [], $headers));
    }

    private function uri(Order $o): string
    {
        return "/orders/{$o->order_no}/invoice";
    }

    private function invoiceId(Order $o): int
    {
        return (int) DB::table('order_invoice')->where('order_id', $o->id)->value('id');
    }

    private function uploadPdf(): string
    {
        return (string) $this->adminApi('POST', '/upload/invoice', ['file' => UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n% test")])
            ->assertJsonPath('code', 0)->json('data.url');
    }

    public function test_invoice_is_off_by_default_and_needs_a_paid_order(): void
    {
        $order = $this->paidOrder();
        $this->assertSame(['can_apply' => false, 'status' => null], $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.invoice'));
        $this->api('POST', $this->uri($order), self::PERSONAL, $this->token)->assertJsonPath('msg', '暂不支持申请发票');

        $this->enable();
        $info = $this->api('GET', $this->uri($order), [], $this->token)->json('data');
        $this->assertSame([true, '', '200.00', '电子发票在 3 个工作日内开出', null, null], [$info['can_apply'], $info['reason'], $info['amount'], $info['notice'], $info['invoice'], $info['last']]);
        $this->assertSame('Issued within 3 business days', $this->api('GET', $this->uri($order), [], $this->token, ['X-Lang' => 'en'])->json('data.notice'));

        // 没付款的订单不能申请
        $unpaid = $this->placeOrder($this->token, $this->sku, 1, $this->addressId);
        $this->api('POST', $this->uri($unpaid), self::PERSONAL, $this->token)->assertJsonPath('msg', '订单付款后才能申请发票');
        $this->assertFalse($this->api('GET', "/orders/{$unpaid->order_no}", [], $this->token)->json('data.invoice.can_apply'));
        // 非人民币订单不能申请
        $usd = $this->paidOrder(1, ['X-Currency' => 'USD']);
        $this->api('POST', $this->uri($usd), self::PERSONAL, $this->token)->assertJsonPath('msg', '该币种的订单暂不支持开具发票');
        // 提示按买家的语言给
        $this->api('POST', $this->uri($usd), self::PERSONAL, $this->token, ['X-Lang' => 'en'])->assertJsonPath('msg', 'Invoices are not available for orders in this currency');
    }

    public function test_buyer_applies_edits_and_cancels_before_it_is_issued(): void
    {
        $this->enable();
        $order = $this->paidOrder();

        // 企业抬头必须有税号；邮箱必填
        $this->api('POST', $this->uri($order), ['tax_no' => '123'] + self::COMPANY, $this->token)->assertJsonPath('msg', '请填写正确的纳税人识别号');
        $this->api('POST', $this->uri($order), ['email' => ''] + self::PERSONAL, $this->token)->assertJsonPath('code', 422);
        $this->assertSame(0, DB::table('order_invoice')->count());

        $inv = $this->api('POST', $this->uri($order), self::COMPANY, $this->token)->assertJsonPath('code', 0)->json('data');
        $this->assertSame([2, '91330100MA2ABCDE1X', '200.00', 0, '招商银行杭州分行', ''], [$inv['title_type'], $inv['tax_no'], $inv['amount'], $inv['status'], $inv['company_info']['bank'], $inv['company_info']['address']]);
        $this->assertSame(['can_apply' => true, 'status' => 0], $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.invoice'));

        // 开出之前可以改：改成个人后税号和企业资料清掉，还是同一条记录
        $inv = $this->api('POST', $this->uri($order), self::PERSONAL + ['tax_no' => '91330100MA2ABCDE1X'], $this->token)->json('data');
        $this->assertSame([1, '张三', '', ''], [$inv['title_type'], $inv['title'], $inv['tax_no'], $inv['company_info']['bank']]);
        $this->assertSame(1, DB::table('order_invoice')->count());

        // 另一张订单申请时带出上次的抬头
        $second = $this->paidOrder(1);
        $this->assertSame('张三', $this->api('GET', $this->uri($second), [], $this->token)->json('data.last.title'));

        // 撤销后可以重新申请
        $this->api('DELETE', $this->uri($order), [], $this->token)->assertJsonPath('code', 0);
        $this->api('DELETE', $this->uri($order), [], $this->token)->assertJsonPath('msg', '还没有申请发票');
        $this->assertSame(['can_apply' => true, 'status' => null], $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.invoice'));
        $this->api('POST', $this->uri($order), self::COMPANY, $this->token)->assertJsonPath('code', 0);
    }

    public function test_merchant_issues_and_voids_and_buyer_cannot_change_an_issued_invoice(): void
    {
        $this->enable();
        $order = $this->paidOrder();
        $this->api('POST', $this->uri($order), self::COMPANY, $this->token)->assertJsonPath('code', 0);
        $id = $this->invoiceId($order);

        $row = $this->adminApi('GET', '/order/invoice/list', ['status' => 0, 'keyword' => '某某科技'])->json('data.list.0');
        $this->assertSame([$id, $order->order_no, '200.00', '200.00'], [$row['id'], $row['order_no'], $row['amount'], $row['invoiceable']]);

        // 文件必须是通过发票上传接口传的；金额不能超过可开票金额
        $url = $this->uploadPdf();
        $this->adminApi('POST', "/order/invoice/{$id}/issue", ['invoice_no' => '25332000000012345678', 'file_url' => 'https://evil.example.com/invoice/a.pdf'])->assertJsonPath('msg', '文件无效，请重新上传');
        $this->adminApi('POST', "/order/invoice/{$id}/issue", ['invoice_no' => '25332000000012345678', 'file_url' => $url, 'amount' => '200.01'])->assertJsonPath('code', 1);
        $this->adminApi('POST', '/upload/invoice', ['file' => UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo 1;')])->assertJsonPath('msg', 'PDF 文件无效');

        $this->adminApi('POST', "/order/invoice/{$id}/issue", ['invoice_no' => '25332000000012345678', 'file_url' => $url, 'amount' => '180.00'])->assertJsonPath('code', 0);
        $inv = $this->api('GET', $this->uri($order), [], $this->token)->json('data');
        $this->assertSame([false, 1, '25332000000012345678', $url, '180.00'], [$inv['can_apply'], $inv['invoice']['status'], $inv['invoice']['invoice_no'], $inv['invoice']['file_url'], $inv['invoice']['amount']]);
        // 买家收到站内信，点了去订单
        $msg = DB::table('member_message')->where('member_id', $this->memberId)->where('scene', 'invoice_issued')->first();
        $this->assertSame(['order', $order->order_no], [$msg->link_type, $msg->link_value]);
        $this->assertStringContainsString('25332000000012345678', $msg->content);

        // 开出后买家不能改、不能撤；商家也不能重复开
        $this->api('POST', $this->uri($order), self::PERSONAL, $this->token)->assertJsonPath('msg', '发票已开出，不能修改');
        $this->api('DELETE', $this->uri($order), [], $this->token)->assertJsonPath('msg', '发票已开出，不能撤销');
        $this->adminApi('POST', "/order/invoice/{$id}/issue", ['invoice_no' => 'X', 'file_url' => $url])->assertJsonPath('msg', '只有待开票的申请可以开票');
        $this->adminApi('POST', "/order/invoice/{$id}/reject", ['reason' => '晚了'])->assertJsonPath('msg', '只有待开票的申请可以驳回');

        // 作废：买家看不到文件了，可以重新申请（回到待开票，发票号码清掉）
        $this->adminApi('POST', "/order/invoice/{$id}/void", [])->assertJsonPath('code', 422);
        $this->adminApi('POST', "/order/invoice/{$id}/void", ['reason' => '抬头开错'])->assertJsonPath('code', 0);
        $inv = $this->api('GET', $this->uri($order), [], $this->token)->json('data');
        $this->assertSame([true, 3, '', '抬头开错'], [$inv['can_apply'], $inv['invoice']['status'], $inv['invoice']['file_url'], $inv['invoice']['reject_reason']]);
        $again = $this->api('POST', $this->uri($order), self::PERSONAL, $this->token)->json('data');
        $this->assertSame([0, '', '200.00'], [$again['status'], $again['invoice_no'], $again['amount']]);
        $this->assertSame($id, $this->invoiceId($order));
    }

    public function test_rejected_request_can_be_fixed_and_resubmitted(): void
    {
        $this->enable();
        $order = $this->paidOrder();
        $this->api('POST', $this->uri($order), self::COMPANY, $this->token);
        $id = $this->invoiceId($order);

        $this->adminApi('POST', "/order/invoice/{$id}/reject", ['reason' => '税号和抬头对不上'])->assertJsonPath('code', 0);
        $inv = $this->api('GET', $this->uri($order), [], $this->token)->json('data');
        $this->assertSame([true, 2, '税号和抬头对不上'], [$inv['can_apply'], $inv['invoice']['status'], $inv['invoice']['reject_reason']]);
        $this->assertSame(1, DB::table('member_message')->where('member_id', $this->memberId)->where('scene', 'invoice_rejected')->count());

        $fixed = $this->api('POST', $this->uri($order), ['tax_no' => '91330100MA2ABCDE2Y'] + self::COMPANY, $this->token)->json('data');
        $this->assertSame([0, ''], [$fixed['status'], $fixed['reject_reason']]);
    }

    public function test_amount_follows_refunds(): void
    {
        $this->enable();
        $order = $this->paidOrder(3);
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $refund = function (array $data) use ($order): void {
            $no = $this->api('POST', "/orders/{$order->order_no}/refunds", $data, $this->token)->assertJsonPath('code', 0)->json('data.refund_no');
            $rid = (int) OrderRefund::query()->where('refund_no', $no)->value('id');
            app(OrderRefundService::class)->approve($rid, 1);
            app(OrderRefundService::class)->pay($rid, 1, true);
        };

        // 退掉 1 件：可开票金额变成 200；申请时按这个数记下
        $refund(['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '测试']);
        $this->assertSame('200.00', $this->api('GET', $this->uri($order), [], $this->token)->json('data.amount'));
        $this->assertSame('200.00', $this->api('POST', $this->uri($order), self::PERSONAL, $this->token)->json('data.amount'));

        // 申请之后又退 1 件：开票时不能再按 200 开
        $refund(['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '测试']);
        $id = $this->invoiceId($order);
        $url = $this->uploadPdf();
        $this->adminApi('POST', "/order/invoice/{$id}/issue", ['invoice_no' => 'A1', 'file_url' => $url, 'amount' => '200.00'])->assertJsonPath('code', 1);
        $this->adminApi('POST', "/order/invoice/{$id}/issue", ['invoice_no' => 'A1', 'file_url' => $url])->assertJsonPath('code', 0);
        $this->assertSame('100.00', (string) DB::table('order_invoice')->where('id', $id)->value('amount'));

        // 全部退完的订单不能申请
        $gone = $this->paidOrder(1);
        $no = $this->api('POST', "/orders/{$gone->order_no}/refunds", ['type' => 1, 'reason' => '不要了'], $this->token)->json('data.refund_no');
        $rid = (int) OrderRefund::query()->where('refund_no', $no)->value('id');
        app(OrderRefundService::class)->approve($rid, 1);
        app(OrderRefundService::class)->pay($rid, 1, true);
        $this->api('POST', $this->uri($gone), self::PERSONAL, $this->token)->assertJsonPath('msg', '订单已全额退款，不能开具发票');
    }
}
