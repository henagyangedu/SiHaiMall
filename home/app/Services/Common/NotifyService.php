<?php

namespace App\Services\Common;

use App\Mail\TemplateMail;
use App\Models\Member;
use App\Models\MessageTemplate;
use App\Support\MessageScenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use function Illuminate\Support\defer;

/**
 * 业务通知（目前只有邮件）。各业务节点调用 send()，是否发送、发什么内容由后台“消息模板”决定。
 *
 * - 场景默认不发送：后台把该场景的邮件模板打开后才发。
 * - 语言按会员的语言偏好；该语言的模板没填时用默认语言的，再没有用内置默认模板。
 * - 只发给有邮箱的会员（邮箱注册的会员）。
 * - 发信放在数据库事务提交之后、响应返回之后执行，失败只写日志，不影响业务。
 */
class NotifyService
{
    public function __construct(private ConfigService $config, private LocaleService $locale, private TranslationService $trans) {}

    /** @param array<string,scalar> $vars 场景变量，见 MessageScenes::ALL[场景]['vars'] */
    public function send(string $scene, int $memberId, array $vars): void
    {
        if (!isset(MessageScenes::ALL[$scene])) {
            return;
        }
        // 事务回滚时不发；没有事务时立即进入下一步
        DB::afterCommit(function () use ($scene, $memberId, $vars) {
            defer(function () use ($scene, $memberId, $vars) {
                try {
                    $this->deliver($scene, $memberId, $vars);
                } catch (\Throwable $e) {
                    Log::warning('通知发送失败', ['scene' => $scene, 'member_id' => $memberId, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                }
            });
        });
    }

    /** 实际渲染并发送，返回是否发出（场景没开启、会员没有邮箱时返回 false） */
    public function deliver(string $scene, int $memberId, array $vars): bool
    {
        $tpl = MessageTemplate::query()->where('scene', $scene)->where('channel', MessageTemplate::CHANNEL_EMAIL)->first();
        if (!$tpl || (int) $tpl->is_enabled !== 1) {
            return false;
        }
        $member = Member::query()->find($memberId);
        $email = trim((string) ($member->email ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $lang = in_array($member->lang, $this->locale->codes(), true) ? (string) $member->lang : $this->locale->defaultLang();
        [$title, $content] = $this->compose($scene, (int) $tpl->id, $lang, $vars + ['nickname' => (string) $member->nickname]);
        Mail::to($email)->send(new TemplateMail($title, $content, $this->siteName($lang)));

        return true;
    }

    /**
     * 渲染某场景某语言的标题和正文（测试发送也用它）。
     * @return array{0:string,1:string}
     */
    public function compose(string $scene, int $templateId, string $lang, array $vars): array
    {
        $texts = $templateId > 0 ? ($this->trans->fetch('message_template_lang', 'template_id', [$templateId], ['title', 'content'])[$templateId] ?? []) : [];
        $vars += ['site_name' => $this->siteName($lang)];

        $out = [];
        foreach (['title', 'content'] as $field) {
            $tpl = trim((string) ($texts[$field][$lang] ?? '')) ?: trim((string) ($texts[$field][$this->locale->defaultLang()] ?? ''));
            // 当前语言和默认语言都没填：用内置默认模板（按当前语言选中文或英文）
            $out[] = MessageScenes::render($tpl !== '' ? $tpl : MessageScenes::default($scene, $field, $lang), $vars);
        }

        return $out;
    }

    private function siteName(string $lang): string
    {
        return trim((string) $this->config->value('base', 'site_name', $lang)) ?: (string) config('app.name');
    }
}
