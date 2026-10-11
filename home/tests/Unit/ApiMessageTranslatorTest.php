<?php

namespace Tests\Unit;

use App\Support\ApiMessageTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** C 端提示的英文翻译：固定文案、带变量的文案、校验提示里的字段名，以及“新增提示必须登记翻译”的检查 */
class ApiMessageTranslatorTest extends TestCase
{
    public function test_chinese_locales_and_non_chinese_text_are_untouched(): void
    {
        $this->assertSame('账号或密码错误', ApiMessageTranslator::translate('账号或密码错误', 'zh-CN'));
        $this->assertSame('账号或密码错误', ApiMessageTranslator::translate('账号或密码错误', 'zh-TW'));
        $this->assertSame('ok', ApiMessageTranslator::translate('ok', 'en'));
        $this->assertSame('', ApiMessageTranslator::translate('', 'en'));
    }

    public function test_other_languages_get_english(): void
    {
        $this->assertSame('Incorrect account or password', ApiMessageTranslator::translate('账号或密码错误', 'en'));
        // 没有自己译文的语言用英文
        $this->assertSame('Incorrect account or password', ApiMessageTranslator::translate('账号或密码错误', 'ko'));
        $this->assertSame('Not enough stock. Only 3 left.', ApiMessageTranslator::translate('库存不足，仅剩 3 件', 'pt-BR'));
    }

    public function test_languages_with_their_own_translations(): void
    {
        $this->assertSame('Cuenta o contraseña incorrecta', ApiMessageTranslator::translate('账号或密码错误', 'es'));
        // 带地区的编码按前半段找
        $this->assertSame('Cuenta o contraseña incorrecta', ApiMessageTranslator::translate('账号或密码错误', 'es-MX'));
        $this->assertSame('No hay suficiente stock. Solo quedan 3.', ApiMessageTranslator::translate('库存不足，仅剩 3 件', 'es'));
        // 状态名跟着语言换；嵌在里面的另一条提示也翻译
        $this->assertSame('El estado del pedido ha cambiado (ahora: cancelado). Actualiza e inténtalo de nuevo.', ApiMessageTranslator::translate('订单状态已变化（当前：已取消），请刷新后重试', 'es'));
        $this->assertSame('Este cupón no se puede usar en este pedido: Este cupón ha caducado', ApiMessageTranslator::translate('这张优惠券不能用于本单：这张优惠券已过期', 'es'));
        // 校验提示在所有非中文语言下都是英文句子，字段名换成英文
        $this->assertSame('The recipient field is required.', ApiMessageTranslator::translate('The 收件人 field is required.', 'es'));
        // 没登记的提示保持中文原文
        $this->assertSame('这是一条还没登记翻译的商品提示', ApiMessageTranslator::translate('这是一条还没登记翻译的商品提示', 'ru'));

        foreach (ApiMessageTranslator::LANGS as $lang) {
            $text = ApiMessageTranslator::translate('账号或密码错误', $lang);
            $this->assertNotSame('Incorrect account or password', $text, "{$lang} 应该有自己的译文");
            $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]{4,}/u', ApiMessageTranslator::translate('订单不存在', $lang === 'ja' ? 'es' : $lang));
        }
    }

    /** 每种语言的译文要和英文一一对应：不能漏、不能多、不能是空的，带变量的模板里变量要一样 */
    public function test_every_language_file_matches_english(): void
    {
        $english = ApiMessageTranslator::english();
        $tokens = function (string $template): array {
            preg_match_all('/\{(?:(?:status|t):)?\d\}/', $template, $m);
            sort($m[0]);

            return $m[0];
        };
        $problems = [];
        foreach (ApiMessageTranslator::LANGS as $lang) {
            $table = require dirname(__DIR__, 2)."/app/Support/ApiMessages/{$lang}.php";
            foreach (['exact', 'patterns', 'status'] as $part) {
                foreach (array_diff_key($english[$part], $table[$part]) as $key => $_) {
                    $problems[] = "{$lang} 的 {$part} 缺少：{$key}";
                }
                foreach (array_diff_key($table[$part], $english[$part]) as $key => $_) {
                    $problems[] = "{$lang} 的 {$part} 多了英文里没有的：{$key}";
                }
                foreach ($table[$part] as $key => $text) {
                    if (trim((string) $text) === '') {
                        $problems[] = "{$lang} 的 {$part} 是空的：{$key}";
                    }
                }
            }
            foreach ($table['patterns'] as $id => $template) {
                if (isset($english['patterns'][$id]) && $tokens($template) !== $tokens($english['patterns'][$id])) {
                    $problems[] = "{$lang} 的模板 {$id} 变量和英文对不上";
                }
            }
        }
        $this->assertSame([], $problems);
    }

    #[DataProvider('patternCases')]
    public function test_messages_with_variables(string $zh, string $en): void
    {
        $this->assertSame($en, ApiMessageTranslator::translate($zh, 'en'));
    }

    public static function patternCases(): array
    {
        return [
            ['库存不足，当前库存 3 件', 'Not enough stock. Only 3 available.'],
            ['库存不足：SKU#12，剩余 0', 'Not enough stock for SKU#12. Only 0 left.'],
            ['登录失败次数过多，请 15 分钟后再试', 'Too many failed sign-in attempts. Please try again in 15 minutes.'],
            ['退款金额必须大于 0 且不超过 79.90 USD', 'The refund amount must be greater than 0 and no more than 79.90 USD'],
            ['订单状态已变化（当前：已取消），请刷新后重试', 'The order status has changed (now cancelled). Please refresh and try again.'],
            ['售后单状态已变化（当前：待买家退货），请刷新后重试', 'The refund status has changed (now return required). Please refresh and try again.'],
            ['订单完成已超过 7 天，不能再申请售后', 'This order was completed more than 7 days ago and is no longer eligible for a refund'],
            ['提现金额不能低于 100.00', 'The minimum withdrawal amount is 100.00'],
        ];
    }

    public function test_validation_messages_get_english_field_names(): void
    {
        // 框架在英文语言下生成的校验提示，字段名来自 FormRequest::attributes() 的中文
        $this->assertSame('The recipient field is required.', ApiMessageTranslator::translate('The 收件人 field is required.', 'en'));
        $this->assertSame('The new password field must be at least 6 characters.', ApiMessageTranslator::translate('The 新密码 field must be at least 6 characters.', 'en'));
        $this->assertSame('The selected verification purpose is invalid.', ApiMessageTranslator::translate('The selected 验证码用途 is invalid.', 'en'));
    }

    public function test_unregistered_chinese_message_is_returned_as_is(): void
    {
        // 没登记的提示保持中文原文，不做半截替换
        $this->assertSame('这是一条还没登记翻译的商品提示', ApiMessageTranslator::translate('这是一条还没登记翻译的商品提示', 'en'));
    }

    /**
     * 买家可达的服务里，每条固定文案的 BusinessException 都必须有英文翻译。
     * 新增提示忘了登记时这个测试会失败，并列出缺的文案。
     */
    public function test_every_buyer_facing_message_is_registered(): void
    {
        $root = dirname(__DIR__, 2).'/app/';
        $files = array_merge(glob($root.'Services/Api/*.php'), array_map(fn ($f) => $root.'Services/Common/'.$f, [
            'SmsCodeService.php', 'EmailCodeService.php', 'OrderCreateService.php', 'OrderPointsService.php',
            'OrderStockService.php', 'ShippingFeeService.php', 'UploadService.php', 'GoodsReviewService.php', 'PointRewardService.php', 'CouponService.php', 'StockSubscribeService.php', 'MemberMessageService.php', 'OrderInvoiceService.php',
        ]), [$root.'Http/Middleware/ApiToken.php']);

        $missing = [];
        $checked = 0;
        foreach ($files as $file) {
            // 只检查整条都是字符串字面量的提示；拼接了变量的由 patterns() 处理，上面的用例单独覆盖
            preg_match_all("/(?:BusinessException|ApiResponse::fail)\\(\\s*'([^']*[\\x{4e00}-\\x{9fff}][^']*)'\\s*[,)]/u", (string) file_get_contents($file), $m);
            foreach ($m[1] as $msg) {
                $checked++;
                if (preg_match('/[\x{4e00}-\x{9fff}]/u', ApiMessageTranslator::translate($msg, 'en'))) {
                    $missing[] = basename($file).'：'.$msg;
                }
            }
        }

        // 防止扫描规则失效后“什么都没查到”也算通过
        $this->assertGreaterThan(40, $checked, '没有扫描到足够多的提示，检查扫描的正则是否还匹配代码写法');
        $this->assertSame([], array_values(array_unique($missing)), '这些买家可见的提示还没有英文翻译，请在 ApiMessageTranslator 里登记');
    }
}
