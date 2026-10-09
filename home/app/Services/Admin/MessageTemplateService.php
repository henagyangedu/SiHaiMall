<?php

namespace App\Services\Admin;

use App\Mail\TemplateMail;
use App\Models\MessageTemplate;
use App\Services\Common\ConfigService;
use App\Services\Common\LocaleService;
use App\Services\Common\NotifyService;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\MessageScenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** 后台消息模板：按场景开关邮件通知、编辑各语言的标题和正文、测试发送。场景和变量定义见 MessageScenes */
class MessageTemplateService
{
    private const FIELDS = ['title', 'content'];

    public function __construct(
        private TranslationService $trans,
        private LocaleService $locale,
        private NotifyService $notify,
        private ConfigService $config,
    ) {}

    public function list(): array
    {
        $rows = MessageTemplate::query()->where('channel', MessageTemplate::CHANNEL_EMAIL)->get()->keyBy('scene');
        $texts = $this->trans->fetch('message_template_lang', 'template_id', $rows->pluck('id')->all(), self::FIELDS);

        $out = [];
        foreach (MessageScenes::ALL as $scene => $def) {
            $row = $rows->get($scene);
            $defaults = [];
            foreach ($this->locale->codes() as $code) {
                $defaults['title'][$code] = MessageScenes::default($scene, 'title', $code);
                $defaults['content'][$code] = MessageScenes::default($scene, 'content', $code);
            }
            $out[] = [
                'scene' => $scene, 'label' => $def['label'], 'is_enabled' => (int) ($row->is_enabled ?? 0),
                'vars' => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys(MessageScenes::COMMON_VARS + $def['vars']), array_values(MessageScenes::COMMON_VARS + $def['vars'])),
                // 管理员填写的内容（没改过则为空）和内置默认模板
                'title' => $row ? ($texts[$row->id]['title'] ?? []) : [], 'content' => $row ? ($texts[$row->id]['content'] ?? []) : [],
                'default_title' => $defaults['title'], 'default_content' => $defaults['content'],
                'customized' => $row && collect($texts[$row->id]['content'] ?? [])->contains(fn ($t) => trim($t) !== ''),
                'updated_at' => $row?->updated_at?->format('Y-m-d H:i:s'),
            ];
        }

        return ['mailer' => (string) config('mail.default'), 'from' => (string) config('mail.from.address'), 'scenes' => $out];
    }

    /** @param array{is_enabled?:int,title?:array,content?:array} $d */
    public function save(string $scene, array $d): void
    {
        $this->assertScene($scene);

        DB::transaction(function () use ($scene, $d) {
            $row = MessageTemplate::query()->firstOrNew(['scene' => $scene, 'channel' => MessageTemplate::CHANNEL_EMAIL]);
            $row->is_enabled = (int) ($d['is_enabled'] ?? $row->is_enabled ?? 0);
            // 没有文本变动时也更新时间，便于在列表看到最后一次操作
            $row->updated_at = now();
            $row->save();

            if (array_key_exists('title', $d) || array_key_exists('content', $d)) {
                $data = [];
                foreach ($this->locale->codes() as $code) {
                    $data['title'][$code] = (string) ($d['title'][$code] ?? '');
                    $data['content'][$code] = (string) ($d['content'][$code] ?? '');
                }
                $this->trans->sync('message_template_lang', 'template_id', (int) $row->id, $data, self::FIELDS);
            }
        });
    }

    /** 测试发送：用示例变量渲染当前保存的模板，发到指定邮箱；不要求场景已开启 */
    public function test(string $scene, string $email, string $lang): array
    {
        $this->assertScene($scene);
        if (!in_array($lang, $this->locale->codes(), true)) {
            $lang = $this->locale->defaultLang();
        }
        $row = MessageTemplate::query()->where('scene', $scene)->where('channel', MessageTemplate::CHANNEL_EMAIL)->first();
        [$title, $content] = $this->notify->compose($scene, (int) ($row->id ?? 0), $lang, $this->sampleVars($scene));

        try {
            Mail::to($email)->send(new TemplateMail($title, $content, trim((string) $this->config->value('base', 'site_name', $lang)) ?: (string) config('app.name')));
        } catch (\Throwable $e) {
            throw new BusinessException('发送失败：' . Str::limit($e->getMessage(), 300));
        }

        return ['title' => $title, 'content' => $content, 'mailer' => (string) config('mail.default')];
    }

    private function sampleVars(string $scene): array
    {
        $samples = [
            'nickname' => '测试会员', 'order_no' => 'GM261009120000123456', 'pay_amount' => '199.00', 'currency' => $this->locale->baseCurrency(),
            'express_name' => 'DHL', 'express_no' => 'DH1234567890', 'refund_no' => 'RF261009120000654321', 'refund_amount' => '99.00',
            'reject_reason' => '商品已超过售后期限', 'withdraw_no' => 'WD261009120000112233', 'amount' => '500.00',
        ];

        return array_intersect_key($samples, MessageScenes::COMMON_VARS + MessageScenes::ALL[$scene]['vars']);
    }

    private function assertScene(string $scene): void
    {
        if (!isset(MessageScenes::ALL[$scene])) {
            throw new BusinessException('消息场景不存在');
        }
    }
}
