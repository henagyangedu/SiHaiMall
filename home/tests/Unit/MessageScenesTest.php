<?php

namespace Tests\Unit;

use App\Support\MessageScenes;
use PHPUnit\Framework\TestCase;

/** 消息模板的变量替换和内置默认模板 */
class MessageScenesTest extends TestCase
{
    public function test_render_replaces_known_variables_and_keeps_unknown_ones(): void
    {
        $out = MessageScenes::render('订单 {order_no} 实付 {pay_amount} {currency}，{typo_var}，{order_no}', [
            'order_no' => 'GM1', 'pay_amount' => '9.90', 'currency' => 'USD',
        ]);

        // 写错的变量名原样保留，方便在测试发送时发现
        $this->assertSame('订单 GM1 实付 9.90 USD，{typo_var}，GM1', $out);
    }

    public function test_render_does_not_expand_values_recursively(): void
    {
        // 变量值里即使带花括号也不会被再次替换（昵称等用户可控内容不能注入其它变量）
        $this->assertSame('你好 {order_no}', MessageScenes::render('你好 {nickname}', ['nickname' => '{order_no}', 'order_no' => 'SECRET']));
    }

    public function test_default_templates_pick_chinese_or_english(): void
    {
        $this->assertStringContainsString('支付成功', MessageScenes::default('order_paid', 'title', 'zh-CN'));
        $this->assertStringContainsString('支付成功', MessageScenes::default('order_paid', 'title', 'zh-TW'));
        $this->assertStringContainsString('Payment received', MessageScenes::default('order_paid', 'title', 'en'));
        $this->assertStringContainsString('Payment received', MessageScenes::default('order_paid', 'title', 'fr'), '没有对应语言时用英文');
        $this->assertSame('', MessageScenes::default('not_exist', 'title', 'en'));
    }

    public function test_every_scene_only_uses_declared_variables(): void
    {
        foreach (MessageScenes::ALL as $scene => $def) {
            $allowed = array_keys(MessageScenes::COMMON_VARS + $def['vars']);
            foreach (['title', 'content'] as $field) {
                foreach (['zh', 'en'] as $lang) {
                    preg_match_all('/\{([a-z_]+)\}/', $def[$field][$lang], $m);
                    $this->assertSame([], array_values(array_diff($m[1], $allowed)), "{$scene}.{$field}.{$lang} 用了未登记的变量");
                }
            }
        }
    }
}
