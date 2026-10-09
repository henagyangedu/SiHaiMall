<?php

namespace Tests\Feature;

use Tests\FeatureTestCase;

/** 小票配置：读取默认值、保存、校验 */
class ReceiptConfigTest extends FeatureTestCase
{
    public function test_defaults_then_save_and_read_back(): void
    {
        $defaults = $this->adminApi('GET', '/config/receipt');
        $defaults->assertJsonPath('code', 0);
        $this->assertSame(['80', 1, 1, 1, 0], [
            (string) $defaults->json('data.paper_width'), (int) $defaults->json('data.show_price'), (int) $defaults->json('data.show_address'),
            (int) $defaults->json('data.show_remark'), (int) $defaults->json('data.show_admin_remark'),
        ]);

        $this->adminApi('PUT', '/config/receipt', [
            'title' => '测试店铺', 'footer' => "感谢惠顾\n售后请联系客服", 'paper_width' => '58', 'show_price' => 0, 'show_address' => 1, 'show_remark' => 0, 'show_admin_remark' => 1,
        ])->assertJsonPath('code', 0);

        $saved = $this->adminApi('GET', '/config/receipt');
        $this->assertSame(['测试店铺', "感谢惠顾\n售后请联系客服", '58', 0, 0, 1], [
            $saved->json('data.title'), $saved->json('data.footer'), (string) $saved->json('data.paper_width'),
            (int) $saved->json('data.show_price'), (int) $saved->json('data.show_remark'), (int) $saved->json('data.show_admin_remark'),
        ]);
    }

    public function test_invalid_values_are_rejected_and_login_is_required(): void
    {
        // 纸宽只有 58 和 80 两种；抬头不能超过 60 个字
        $this->assertNotSame(0, $this->adminApi('PUT', '/config/receipt', ['paper_width' => '100'])->json('code'));
        $this->assertNotSame(0, $this->adminApi('PUT', '/config/receipt', ['title' => str_repeat('长', 61)])->json('code'));
        $this->assertSame('80', (string) $this->adminApi('GET', '/config/receipt')->json('data.paper_width'));

        $this->json('GET', '/admin-api/config/receipt')->assertJsonPath('code', 401);
    }
}
