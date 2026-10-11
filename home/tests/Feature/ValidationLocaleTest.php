<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Services\Common\LocaleService;
use App\Support\ApiMessageTranslator;
use Illuminate\Foundation\Http\FormRequest;
use Tests\FeatureTestCase;

/** 表单校验提示跟着买家的语言走：句子在 lang/语言/validation.php，字段名在 ApiMessages/语言.php 的 attributes */
class ValidationLocaleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['es', 'es-MX', 'fr', 'de', 'ja', 'ru', 'ko'] as $i => $code) {
            Language::query()->updateOrCreate(['code' => $code], ['name' => $code, 'status' => 1, 'sort' => 50 + $i, 'is_default' => 0]);
        }
        app(LocaleService::class)->flush();
    }

    protected function tearDown(): void
    {
        app(LocaleService::class)->flush();
        parent::tearDown();
    }

    public function test_required_message_is_in_the_buyers_language(): void
    {
        $expected = [
            'zh-CN' => '账号 不能为空。',
            'en' => 'The account field is required.',
            'es' => 'El campo «Cuenta» es obligatorio.',
            'fr' => 'Le champ « Compte » est obligatoire.',
            'de' => 'Konto ist erforderlich.',
            'ja' => 'アカウントを入力してください。',
            'ru' => 'Поле «Аккаунт» обязательно для заполнения.',
            // 带地区的编码按前半段找
            'es-MX' => 'El campo «Cuenta» es obligatorio.',
            // 没有译文的语言用英文
            'ko' => 'The account field is required.',
        ];
        foreach ($expected as $lang => $msg) {
            $res = $this->api('POST', '/auth/login', ['password' => 'Secret#123'], null, ['X-Lang' => $lang]);
            $res->assertJsonPath('code', 422);
            $this->assertSame($msg, $res->json('msg'), $lang);
            // data 里按字段列出的全部提示也是同一种语言
            $this->assertSame($msg, $res->json('data.account.0'), $lang);
        }
    }

    public function test_messages_with_numbers_and_other_fields(): void
    {
        // 长度下限
        $short = $this->api('POST', '/auth/register', ['account' => 'someone@example.com', 'password' => '1'], null, ['X-Lang' => 'de']);
        $short->assertJsonPath('code', 422);
        $this->assertMatchesRegularExpression('/^Passwort muss mindestens \d+ Zeichen lang sein\.$/u', (string) $short->json('msg'));

        // 日语的句子里没有英文字母，字段名照样要换掉
        $ja = $this->api('POST', '/auth/register', ['account' => 'someone@example.com', 'password' => '1'], null, ['X-Lang' => 'ja']);
        $this->assertMatchesRegularExpression('/^パスワードは\d+文字以上で入力してください。$/u', (string) $ja->json('msg'));

        // 登录后的接口：收货地址缺收件人
        [, $token] = $this->makeMember();
        $addr = $this->api('POST', '/addresses', ['phone' => '13800000000', 'country_code' => 'US', 'address' => '1 Main St'], $token, ['X-Lang' => 'ru']);
        $addr->assertJsonPath('code', 422);
        $this->assertSame('Поле «Получатель» обязательно для заполнения.', $addr->json('msg'));

        // 没带 X-Lang 时用会员自己的语言
        [, $frToken] = $this->makeMember(['lang' => 'fr']);
        $fr = $this->api('POST', '/addresses', ['phone' => '13800000000', 'country_code' => 'US', 'address' => '1 Main St'], $frToken);
        $this->assertSame('Le champ « Destinataire » est obligatoire.', $fr->json('msg'));
    }

    public function test_admin_validation_stays_chinese(): void
    {
        $res = $this->json('POST', '/admin-api/auth/login', [], ['X-Lang' => 'de']);
        $res->assertJsonPath('code', 422);
        $this->assertMatchesRegularExpression('/\p{Han}/u', (string) $res->json('msg'));
    }

    /** 买家接口的表单里用到的字段名都登记了翻译，否则非中文语言下会漏出中文 */
    public function test_every_buyer_form_attribute_has_a_translation(): void
    {
        $english = ApiMessageTranslator::english()['attributes'];
        $missing = [];
        foreach (glob(app_path('Http/Requests/Api/*.php')) as $file) {
            $class = 'App\\Http\\Requests\\Api\\'.basename($file, '.php');
            $request = new $class;
            $this->assertInstanceOf(FormRequest::class, $request);
            foreach ($request->attributes() as $name) {
                if (preg_match('/\p{Han}/u', (string) $name) && ! isset($english[$name])) {
                    $missing[] = basename($file, '.php').'：'.$name;
                }
            }
        }
        $this->assertSame([], $missing, '这些字段名没有在 ApiMessageTranslator::ATTRIBUTES 里登记');
    }
}
