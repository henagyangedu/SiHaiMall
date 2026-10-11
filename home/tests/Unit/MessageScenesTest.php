<?php

namespace Tests\Unit;

use App\Mail\VerifyCodeMail;
use App\Support\LocalizedTexts;
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
        $this->assertStringContainsString('Payment received', MessageScenes::default('order_paid', 'title', 'ko'), '没有对应语言时用英文');
        $this->assertSame('', MessageScenes::default('not_exist', 'title', 'en'));
        // 西、法、德、日、俄有各自的模板；带地区的编码按前半段找
        $this->assertStringContainsString('Paiement reçu', MessageScenes::default('order_paid', 'title', 'fr'));
        $this->assertStringContainsString('Paiement reçu', MessageScenes::default('order_paid', 'title', 'fr-CA'));
        $this->assertStringContainsString('Bonjour {nickname}', MessageScenes::default('order_paid', 'content', 'fr'));
        $this->assertStringContainsString('{nickname} 様', MessageScenes::default('order_shipped', 'content', 'ja'));
    }

    public function test_verify_code_mail_and_protocol_titles_follow_the_language(): void
    {
        $mail = fn (string $lang, string $purpose = 'register') => new VerifyCodeMail('123456', $purpose, 10, 'Shop', $lang);
        $this->assertSame('[Shop] Dein Bestätigungscode lautet 123456', $mail('de')->envelope()->subject);
        $this->assertSame('[Shop] Your verification code is 123456', $mail('ko')->envelope()->subject);
        $this->assertSame('【Shop】验证码 123456', $mail('zh-CN')->envelope()->subject);
        $html = $mail('de', 'reset')->content()->htmlString;
        $this->assertStringContainsString('Mit diesem Code setzt du dein Passwort zurück:', $html);
        $this->assertStringContainsString('Der Code ist 10 Minuten gültig.', $html);
        $this->assertStringContainsString('Mit diesem Code erstellst du dein Konto:', $mail('de-AT')->content()->htmlString);

        $this->assertSame('Datenschutzerklärung', LocalizedTexts::PROTOCOLS['de']['privacy_policy']);
        $this->assertNull(LocalizedTexts::base('ko'));
        $this->assertSame('ru', LocalizedTexts::base('ru-RU'));
    }

    /** 五种语言的模板要和英文一一对应：每个场景都有，标题和正文都不空，用到的变量和英文模板一样 */
    public function test_localized_templates_match_english(): void
    {
        $vars = function (string $text): array {
            preg_match_all('/\{([a-z_]+)\}/', $text, $m);
            $found = array_values(array_unique($m[1]));
            sort($found);

            return $found;
        };
        foreach (LocalizedTexts::LANGS as $lang) {
            $this->assertSame(array_keys(MessageScenes::ALL), array_keys(LocalizedTexts::SCENES[$lang]), "{$lang} 的场景和 MessageScenes 对不上");
            foreach (MessageScenes::ALL as $scene => $def) {
                foreach (['title' => 0, 'content' => 1] as $field => $i) {
                    $text = LocalizedTexts::SCENES[$lang][$scene][$i];
                    $this->assertNotSame('', trim($text));
                    $this->assertSame($vars($def[$field]['en']), $vars($text), "{$lang}.{$scene}.{$field} 的变量和英文模板不一样");
                }
            }
            $this->assertCount(5, LocalizedTexts::PROTOCOLS[$lang]);
            $this->assertCount(5, LocalizedTexts::VERIFY_MAIL[$lang]);
            $this->assertStringContainsString('{code}', LocalizedTexts::VERIFY_MAIL[$lang][0]);
            $this->assertStringContainsString('{minutes}', LocalizedTexts::VERIFY_MAIL[$lang][3]);
        }
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
