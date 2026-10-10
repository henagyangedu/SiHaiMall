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

/**
 * 后台消息模板：按场景分别开关站内信和邮件、编辑各语言的标题和正文（两个渠道共用一份）、测试发送邮件。
 * 场景和变量定义见 MessageScenes。站内信默认开启（没有记录 = 开启），邮件默认关闭。
 */
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
        $site = MessageTemplate::query()->where('channel', MessageTemplate::CHANNEL_SITE)->pluck('is_enabled', 'scene');
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
                'site_enabled' => (int) ($site[$scene] ?? 1),
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

    /** @param array{is_enabled?:int,site_enabled?:int,title?:array,content?:array} $d is_enabled 是邮件的开关，site_enabled 是站内信的 */
    public function save(string $scene, array $d): void
    {
        $this->assertScene($scene);

        DB::transaction(function () use ($scene, $d) {
            if (isset($d['site_enabled'])) {
                MessageTemplate::query()->updateOrCreate(['scene' => $scene, 'channel' => MessageTemplate::CHANNEL_SITE], ['is_enabled' => (int) $d['site_enabled']]);
                // 只切换站内信开关时，不去动邮件那条记录
                if (count(array_diff(array_keys($d), ['site_enabled'])) === 0) {
                    return;
                }
            }
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
            'goods_title' => '智能喂食器 5 升', 'spec' => '白色',
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
