<?php

namespace Tests;

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use App\Models\MemberAddress;
use App\Models\MemberToken;
use App\Models\Order;
use App\Services\Common\OrderPaidService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * 带数据库的功能测试基类。
 *
 * - 使用独立的 MySQL 测试库（phpunit.xml 里的 gmmall_test），首次运行自动建表；每个测试在事务里跑，结束后回滚。
 * - 提供造数据的小工具（会员、地址、商品）和带令牌请求 C 端 / 后台接口的方法。
 * - 邮件一律截获不发送；“响应之后再执行”的延迟任务改为立即执行，方便断言。
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** 建表前检查：只允许在库名以 _test 结尾的库上跑，防止配置出错时清空开发库 */
    protected function beforeRefreshingDatabase(): void
    {
        $name = (string) DB::connection()->getDatabaseName();
        if (! str_ends_with($name, '_test')) {
            throw new RuntimeException("功能测试只能在测试库上运行，当前连接的是「{$name}」。请检查 phpunit.xml 的 DB_DATABASE，并确认没有缓存配置（php artisan config:clear）。");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutDefer();
    }

    // ------------------------------------------------------------------ 造数据
    /** @return array{0:Member,1:string} 会员和登录令牌 */
    protected function makeMember(array $attrs = []): array
    {
        $n = ++$this->seq;
        $member = Member::query()->create($attrs + [
            'account' => "buyer{$n}@example.com", 'email' => "buyer{$n}@example.com", 'password' => Hash::make('Secret#123'),
            'nickname' => "买家{$n}", 'lang' => 'zh-CN', 'currency' => 'CNY', 'level_id' => 1, 'status' => 1,
        ]);
        $plain = Str::random(64);
        MemberToken::query()->create([
            'member_id' => $member->id, 'token_hash' => hash('sha256', $plain), 'platform' => 'h5',
            'expires_at' => now()->addDay(), 'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'test',
        ]);

        return [$member, $plain];
    }

    protected function makeAddress(int $memberId, array $attrs = []): MemberAddress
    {
        return MemberAddress::query()->create($attrs + [
            'member_id' => $memberId, 'name' => '张三', 'phone_area' => '86', 'phone' => '13800000000', 'country_code' => 'CN',
            'province' => '北京市', 'city' => '北京市', 'district' => '', 'address' => '测试路 1 号', 'is_default' => 1,
        ]);
    }

    /**
     * 造一个已上架的单规格商品，返回 SKU ID。
     *
     * @param  array<string,mixed>  $goods  覆盖商品字段（如 shipping_template_id、is_virtual）
     * @param  array<string,mixed>  $sku  覆盖 SKU 字段（如 weight）
     */
    protected function makeSku(string $price = '100.00', int $stock = 10, array $goods = [], array $sku = []): int
    {
        $n = ++$this->seq;
        $categoryId = DB::table('category')->insertGetId(['pid' => 0, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $goodsId = DB::table('goods')->insertGetId($goods + [
            'category_id' => $categoryId, 'goods_sn' => "T{$n}", 'cover' => '/uploads/t.png', 'images' => '["/uploads/t.png"]', 'spec_type' => 1,
            'price' => $price, 'stock' => $stock, 'is_on_sale' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('goods_lang')->insert([
            ['goods_id' => $goodsId, 'lang' => 'zh-CN', 'title' => "测试商品{$n}"],
            ['goods_id' => $goodsId, 'lang' => 'en', 'title' => "Test product {$n}"],
        ]);

        return (int) DB::table('goods_sku')->insertGetId($sku + [
            'goods_id' => $goodsId, 'sku_sn' => "T{$n}-1", 'price' => $price, 'stock' => $stock, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function stock(int $skuId): int
    {
        return (int) DB::table('goods_sku')->where('id', $skuId)->value('stock');
    }

    /** 买家下单（走 C 端接口），返回订单模型 */
    protected function placeOrder(string $token, int $skuId, int $qty, int $addressId, array $extra = [], array $headers = []): Order
    {
        $r = $this->api('POST', '/orders', ['items' => [['sku_id' => $skuId, 'qty' => $qty]], 'address_id' => $addressId] + $extra, $token, $headers);
        $this->assertSame(0, $r->json('code'), '下单失败：'.$r->json('msg'));

        return Order::query()->where('order_no', $r->json('data.order_no'))->firstOrFail();
    }

    /** 模拟线下收款，把订单标记为已支付 */
    protected function pay(Order $order): Order
    {
        app(OrderPaidService::class)->markPaid((int) $order->id, 'offline', OrderStatus::OP_SYSTEM, 0, '测试收款');

        return $order->fresh();
    }

    // ------------------------------------------------------------------ 发请求
    /** 请求 C 端接口（/api 前缀），可带会员令牌和请求头（如 X-Lang、X-Currency） */
    protected function api(string $method, string $uri, array $data = [], ?string $token = null, array $headers = []): TestResponse
    {
        return $this->json($method, '/api'.$uri, $data, $headers + ($token ? ['Authorization' => "Bearer {$token}"] : []));
    }

    /** 以超级管理员身份请求后台接口（/admin-api 前缀） */
    protected function adminApi(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, '/admin-api'.$uri, $data, $headers + ['Authorization' => 'Bearer '.$this->adminToken()]);
    }

    protected function admin(): Admin
    {
        return Admin::query()->first() ?? Admin::query()->create([
            'username' => 'tester', 'password' => Hash::make('Secret#123'), 'nickname' => '测试管理员', 'role_id' => 0, 'status' => 1,
        ]);
    }

    private ?string $adminPlain = null;

    private function adminToken(): string
    {
        if ($this->adminPlain === null) {
            $this->adminPlain = Str::random(64);
            AdminToken::query()->create([
                'admin_id' => $this->admin()->id, 'token_hash' => hash('sha256', $this->adminPlain), 'expires_at' => now()->addHour(),
                'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'test',
            ]);
        }

        return $this->adminPlain;
    }
}
