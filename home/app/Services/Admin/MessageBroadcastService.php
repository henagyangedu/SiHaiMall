<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Goods;
use App\Models\MemberLevel;
use App\Models\MessageBroadcast;
use App\Services\Common\MemberLookupService;
use App\Services\Common\MessageBroadcastService as Delivery;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 后台的群发消息：新建、列表、撤回、立即发送。只发站内信，不发邮件。
 * 新建只是存下来（待发送），真正写进各会员消息中心的是 Common\MessageBroadcastService；
 * 一旦开始发送就不能撤回，发出去的内容也不能改。
 */
class MessageBroadcastService
{
    /** 指定会员时一次最多多少位 */
    public const MAX_MEMBERS = 500;

    private const LINK_TYPES = ['', 'goods', 'article', 'coupon'];

    public function __construct(
        private TranslationService $trans,
        private MemberLookupService $lookup,
        private Delivery $delivery,
    ) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $p = MessageBroadcast::query()
            ->when(($f['status'] ?? '') !== '' && $f['status'] !== null, fn ($q) => $q->where('status', (int) $f['status']))
            ->orderByDesc('id')->paginate($size);

        $rows = collect($p->items());
        $texts = $this->trans->fetch('message_broadcast_lang', 'broadcast_id', $rows->pluck('id')->all(), ['title', 'content']);
        $admins = Admin::query()->whereIn('id', $rows->pluck('admin_id')->unique()->all())->get(['id', 'nickname', 'username'])->keyBy('id');
        $levelIds = $rows->where('target_type', MessageBroadcast::TARGET_LEVEL)->flatMap(fn ($b) => (array) $b->target_ids)->unique()->values()->all();
        $levelNames = $this->levelNames($levelIds);

        $p->through(function (MessageBroadcast $b) use ($texts, $admins, $levelNames) {
            $ids = array_map('intval', (array) $b->target_ids);
            $admin = $admins->get($b->admin_id);

            return [
                'id' => (int) $b->id,
                'title' => $texts[$b->id]['title'] ?? [], 'content' => $texts[$b->id]['content'] ?? [],
                'title_text' => $this->trans->resolve($texts[$b->id]['title'] ?? []),
                'target_type' => (int) $b->target_type,
                'target_text' => match ((int) $b->target_type) {
                    MessageBroadcast::TARGET_LEVEL => '等级：' . implode('、', array_map(fn ($id) => $levelNames[$id] ?? ('#' . $id), $ids)),
                    MessageBroadcast::TARGET_MEMBER => count($ids) . ' 位指定会员',
                    default => '全部会员',
                },
                'link_type' => (string) $b->link_type, 'link_value' => (string) $b->link_value,
                'status' => (int) $b->status, 'sent_count' => (int) $b->sent_count,
                'admin_name' => $admin ? ((string) $admin->nickname ?: (string) $admin->username) : '',
                'created_at' => $b->created_at?->format('Y-m-d H:i:s'), 'finished_at' => $b->finished_at ? (string) $b->finished_at : null,
            ];
        });

        return Paginate::format($p);
    }

    public function create(array $d, int $adminId): MessageBroadcast
    {
        $title = (array) ($d['title'] ?? []);
        $content = (array) ($d['content'] ?? []);
        $this->trans->assertDefaultFilled($title, '标题');
        $this->trans->assertDefaultFilled($content, '正文');

        $type = (int) $d['target_type'];
        $targets = match ($type) {
            MessageBroadcast::TARGET_LEVEL => $this->levelTargets((array) ($d['level_ids'] ?? [])),
            MessageBroadcast::TARGET_MEMBER => $this->memberTargets((array) ($d['members'] ?? [])),
            default => null,
        };
        [$linkType, $linkValue] = $this->link((string) ($d['link_type'] ?? ''), (int) ($d['link_value'] ?? 0));

        return DB::transaction(function () use ($type, $targets, $linkType, $linkValue, $adminId, $title, $content) {
            $b = MessageBroadcast::query()->create([
                'target_type' => $type, 'target_ids' => $targets, 'link_type' => $linkType, 'link_value' => $linkValue,
                'status' => MessageBroadcast::STATUS_PENDING, 'admin_id' => $adminId,
            ]);
            $this->trans->sync('message_broadcast_lang', 'broadcast_id', (int) $b->id, ['title' => $title, 'content' => $content], ['title', 'content']);

            return $b;
        });
    }

    /** 撤回：只有还没开始发的可以（条件更新，和投递抢不出两种结果） */
    public function revoke(int $id): void
    {
        $n = MessageBroadcast::query()->where('id', $id)->where('status', MessageBroadcast::STATUS_PENDING)
            ->update(['status' => MessageBroadcast::STATUS_REVOKED, 'updated_at' => now()]);
        if ($n === 0) {
            throw new BusinessException(MessageBroadcast::query()->whereKey($id)->exists() ? '只有还没开始发送的消息可以撤回' : '群发消息不存在');
        }
    }

    /** 立即发送：不等定时任务，手动跑同一段投递逻辑；人数很多一次发不完时，剩下的由定时任务或再点一次接着发 */
    public function sendNow(int $id): array
    {
        $b = MessageBroadcast::query()->find($id) ?? throw new BusinessException('群发消息不存在');
        if (!in_array((int) $b->status, [MessageBroadcast::STATUS_PENDING, MessageBroadcast::STATUS_SENDING], true)) {
            throw new BusinessException((int) $b->status === MessageBroadcast::STATUS_REVOKED ? '这条消息已撤回' : '这条消息已经发完了');
        }
        $this->delivery->run($id);
        $b->refresh();

        return ['status' => (int) $b->status, 'sent_count' => (int) $b->sent_count];
    }

    private function levelTargets(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            throw new BusinessException('请选择会员等级');
        }
        if (MemberLevel::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw new BusinessException('选择的会员等级不存在');
        }

        return $ids;
    }

    private function memberTargets(array $accounts): array
    {
        $accounts = $this->lookup->clean($accounts);
        if ($accounts === []) {
            throw new BusinessException('请填写要发给哪些会员');
        }
        if (count($accounts) > self::MAX_MEMBERS) {
            throw new BusinessException('指定会员一次最多 ' . self::MAX_MEMBERS . ' 位');
        }

        return $this->lookup->idsByAccounts($accounts);
    }

    /** @return array{0:string,1:string} */
    private function link(string $type, int $value): array
    {
        if (!in_array($type, self::LINK_TYPES, true)) {
            throw new BusinessException('跳转类型不正确');
        }
        if ($type === 'goods' && !Goods::query()->whereKey($value)->exists()) {
            throw new BusinessException('要跳转的商品不存在');
        }
        if ($type === 'article' && !Article::query()->whereKey($value)->exists()) {
            throw new BusinessException('要跳转的文章不存在');
        }

        return [$type, in_array($type, ['goods', 'article'], true) ? (string) $value : ''];
    }

    private function levelNames(array $ids): array
    {
        $names = $this->trans->fetch('member_level_lang', 'level_id', $ids, ['name']);

        return array_map(fn ($n) => $this->trans->resolve($n['name'] ?? []), $names);
    }
}
