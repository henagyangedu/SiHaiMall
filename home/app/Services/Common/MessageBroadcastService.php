<?php

namespace App\Services\Common;

use App\Models\Member;
use App\Models\MessageBroadcast;
use Illuminate\Support\Facades\DB;

/**
 * 群发消息的投递：把后台建好的群发消息分批写进各会员的消息中心。
 * 由定时任务 mall:message-broadcast 每分钟调用，后台的“立即发送”也走这里。
 * 每一批在一个事务里完成“锁住这条群发 → 取下一批会员 → 写站内信 → 记下发到哪个会员”，
 * 所以中断后接着发不会重复，定时任务和手动发送同时跑也不会重复。
 */
class MessageBroadcastService
{
    /** 每批多少位会员 */
    public const BATCH = 500;

    public function __construct(
        private MemberMessageService $messages,
        private TranslationService $trans,
        private LocaleService $locale,
    ) {}

    /**
     * 处理待发送和发送中的群发消息（$onlyId 指定时只处理这一条），返回这次写了多少条站内信。
     * $maxBatches 限制一次调用最多跑多少批，没发完的下次接着发。
     */
    public function run(?int $onlyId = null, int $maxBatches = 40, int $batchSize = self::BATCH): int
    {
        $ids = MessageBroadcast::query()
            ->whereIn('status', [MessageBroadcast::STATUS_PENDING, MessageBroadcast::STATUS_SENDING])
            ->when($onlyId !== null, fn ($q) => $q->where('id', $onlyId))
            ->orderBy('id')->pluck('id');

        $sent = 0;
        foreach ($ids as $id) {
            while ($maxBatches > 0) {
                $maxBatches--;
                $n = $this->batch((int) $id, $batchSize);
                if ($n === null) {
                    break;
                }
                $sent += $n;
            }
            if ($maxBatches <= 0) {
                break;
            }
        }

        return $sent;
    }

    /** 发一批；返回这批写了多少条，这条群发已经发完（或已撤回）时返回 null */
    private function batch(int $id, int $batchSize): ?int
    {
        return DB::transaction(function () use ($id, $batchSize) {
            $b = MessageBroadcast::query()->lockForUpdate()->find($id);
            if (!$b || !in_array((int) $b->status, [MessageBroadcast::STATUS_PENDING, MessageBroadcast::STATUS_SENDING], true)) {
                return null;
            }

            $targets = array_map('intval', (array) $b->target_ids);
            $members = Member::query()->where('status', 1)->where('id', '>', (int) $b->last_member_id)
                ->when((int) $b->target_type === MessageBroadcast::TARGET_LEVEL, fn ($q) => $q->whereIn('level_id', $targets))
                ->when((int) $b->target_type === MessageBroadcast::TARGET_MEMBER, fn ($q) => $q->whereIn('id', $targets))
                ->orderBy('id')->limit($batchSize)->get(['id', 'lang']);

            if ($members->isNotEmpty()) {
                $texts = $this->trans->fetch('message_broadcast_lang', 'broadcast_id', [$id], ['title', 'content'])[$id] ?? [];
                $codes = $this->locale->codes();
                $byLang = [];
                $rows = [];
                foreach ($members as $m) {
                    // 会员的语言没启用（或没记录）时按默认语言发；那种语言没填的，resolve 会回退到默认语言
                    $lang = in_array($m->lang, $codes, true) ? (string) $m->lang : $this->locale->defaultLang();
                    $byLang[$lang] ??= [$this->trans->resolve($texts['title'] ?? [], $lang), $this->trans->resolve($texts['content'] ?? [], $lang)];
                    $rows[] = ['member_id' => (int) $m->id, 'title' => $byLang[$lang][0], 'content' => $byLang[$lang][1]];
                }
                $this->messages->pushMany(MessageBroadcast::SCENE, $rows, [(string) $b->link_type, (string) $b->link_value]);
            }

            // 不满一批说明后面没有人了，直接收尾
            $done = $members->count() < $batchSize;
            $b->update([
                'status' => $done ? MessageBroadcast::STATUS_DONE : MessageBroadcast::STATUS_SENDING,
                'last_member_id' => $members->isEmpty() ? (int) $b->last_member_id : (int) $members->last()->id,
                'sent_count' => (int) $b->sent_count + $members->count(),
                'finished_at' => $done ? now() : null,
            ]);

            return $members->isEmpty() ? null : $members->count();
        });
    }
}
