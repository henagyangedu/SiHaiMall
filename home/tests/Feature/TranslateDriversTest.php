<?php

namespace Tests\Feature;

use App\Drivers\DriverManager;
use App\Drivers\Translate\ClaudeDriver;
use App\Drivers\Translate\OpenAiCompatibleDriver;
use App\Models\DriverConfig;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** AI 翻译的模型驱动（Claude、通义千问）以及翻译接口对模型输出的校验 */
class TranslateDriversTest extends FeatureTestCase
{
    private function enable(string $driver, array $config): void
    {
        DriverConfig::query()->create(['type' => 'translate', 'driver' => $driver, 'name' => $driver, 'is_enabled' => 1, 'is_default' => 1, 'sort' => 0, 'config' => $config]);
    }

    private function claudeReply(string $text, string $stop = 'end_turn'): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => $stop, 'usage' => ['input_tokens' => 10, 'output_tokens' => 10]];
    }

    private function translate(array $fields)
    {
        return $this->adminApi('POST', '/translate', ['from' => 'zh-CN', 'to' => 'en', 'fields' => $fields]);
    }

    public function test_claude_driver_calls_the_messages_api(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hello, '], ['type' => 'text', 'text' => 'welcome']], 'stop_reason' => 'end_turn'])]);
        $driver = new ClaudeDriver(['base_url' => 'https://api.anthropic.com/', 'api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-5-5', 'temperature' => '0.2', 'max_tokens' => '4096']);

        $this->assertSame('Hello, welcome', $driver->complete('You are a translator.', '你好，欢迎光临', false));
        $this->assertSame(['claude', 'claude-sonnet-5-5'], [$driver->code(), $driver->model()]);

        Http::assertSent(function (HttpRequest $req) {
            return $req->url() === 'https://api.anthropic.com/v1/messages'
                && $req->header('x-api-key') === ['sk-ant-test'] && $req->header('anthropic-version') === ['2023-06-01']
                && ! $req->hasHeader('Authorization')
                && $req->data() === ['model' => 'claude-sonnet-5-5', 'max_tokens' => 4096, 'system' => 'You are a translator.',
                    'messages' => [['role' => 'user', 'content' => '你好，欢迎光临']], 'temperature' => 0.2];
        });
    }

    public function test_claude_driver_reports_errors_without_leaking_the_key(): void
    {
        $driver = new ClaudeDriver(['base_url' => 'https://api.anthropic.com', 'api_key' => 'sk-ant-SECRET', 'model' => 'm']);
        $message = function () use ($driver): string {
            try {
                $driver->complete('s', 'u');
            } catch (BusinessException $e) {
                return $e->getMessage();
            }

            return '';
        };

        Http::fake(['*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key sk-ant-SECRET']], 401)
            ->push($this->claudeReply('{"fields":{"title":"half of the te', 'max_tokens'))
            ->push(['content' => [], 'stop_reason' => 'end_turn'])]);

        $auth = $message();
        $this->assertStringContainsString('401', $auth);
        $this->assertStringNotContainsString('sk-ant-SECRET', $auth);
        $this->assertStringContainsString('截断', $message());
        $this->assertStringContainsString('没有返回内容', $message());

        // 没配完整时不发请求；不填最大输出长度时用默认值
        $this->expectException(BusinessException::class);
        (new ClaudeDriver(['base_url' => 'https://api.anthropic.com', 'api_key' => '', 'model' => 'm']))->complete('s', 'u');
    }

    public function test_driver_manager_picks_the_right_implementation(): void
    {
        $manager = app(DriverManager::class);

        $this->assertInstanceOf(ClaudeDriver::class, $manager->translateFromConfig('claude', []));
        foreach (['openai', 'deepseek', 'qwen'] as $code) {
            $this->assertInstanceOf(OpenAiCompatibleDriver::class, $manager->translateFromConfig($code, []));
        }
    }

    public function test_qwen_uses_the_openai_compatible_endpoint(): void
    {
        $this->enable('qwen', ['base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'api_key' => 'sk-qwen', 'model' => 'qwen-plus']);
        Http::fake(['dashscope.aliyuncs.com/*' => Http::response(['choices' => [['message' => ['content' => '{"fields":{"title":"Cotton T-shirt"},"warnings":[]}']]]])]);

        $r = $this->translate(['title' => '纯棉 T 恤']);

        $r->assertJsonPath('code', 0);
        $this->assertSame(['Cotton T-shirt', 'qwen', 'qwen-plus'], [$r->json('data.fields.title'), $r->json('data.driver'), $r->json('data.model')]);
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions'
            && $req->header('Authorization') === ['Bearer sk-qwen'] && $req['model'] === 'qwen-plus' && $req['response_format'] === ['type' => 'json_object']);
    }

    public function test_translation_through_claude_accepts_fenced_json_and_keeps_html_intact(): void
    {
        $this->enable('claude', ['base_url' => 'https://api.anthropic.com', 'api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-5-5']);
        $html = '<p>欢迎光临</p><p><img src="/uploads/a.png"></p>';
        // Claude 常把 JSON 包在代码块里返回
        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeReply("```json\n".json_encode(['fields' => ['title' => 'Welcome', 'content' => '<p>Welcome</p><p><img src="/uploads/a.png"></p>'], 'warnings' => []])."\n```"))]);

        $r = $this->translate(['title' => '欢迎', 'content' => $html]);

        $r->assertJsonPath('code', 0);
        $this->assertSame(['Welcome', '<p>Welcome</p><p><img src="/uploads/a.png"></p>', 'claude'], [$r->json('data.fields.title'), $r->json('data.fields.content'), $r->json('data.driver')]);
        // 系统提示词要求输出 JSON，并且用的是 system 字段而不是塞进用户消息
        Http::assertSent(fn (HttpRequest $req) => str_contains($req['system'], 'valid JSON') && str_contains($req['messages'][0]['content'], '欢迎光临'));

        // 同样的内容再翻译一次走缓存，不再请求模型
        $this->translate(['title' => '欢迎', 'content' => $html])->assertJsonPath('data.cached', true);
        Http::assertSentCount(1);
    }

    public function test_a_translation_that_breaks_the_html_structure_is_retried_then_rejected(): void
    {
        $this->enable('claude', ['base_url' => 'https://api.anthropic.com', 'api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-5-5']);
        // 文章正文（字段名不是 description）被模型丢了一段、图片也没了：不能当成译文返回
        Http::fake(['api.anthropic.com/*' => Http::response($this->claudeReply(json_encode(['fields' => ['content' => '<p>Welcome</p>'], 'warnings' => []])))]);

        $r = $this->translate(['content' => '<p>欢迎光临</p><p><img src="/uploads/a.png"></p>']);

        $r->assertJsonPath('code', 1);
        $this->assertStringContainsString('HTML 结构', $r->json('msg'));
        Http::assertSentCount(2); // 不合格时自动重试一次
    }
}
