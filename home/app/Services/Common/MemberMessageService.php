<?php

namespace App\Services\Common;

use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 站内信（买家的消息中心）。写入只有一个入口：NotifyService 在发通知时调 push()；
 * 标题和正文在写入时已经按会员的语言生成好，这里不再翻译。超过保留期的由 mall:message-cleanup 清理。
 */
class MemberMessageService
{
    /** 消息保留多少天 */
    public const KEEP_DAYS = 180;

    /** @param array{0:string,1:string} $link [跳转类型, 跳转的值]，不跳转传 ['', ''] */
    public function push(int $memberId, string $scene, string $title, string $content, array $link = ['', '']): void
    {
        DB::table('member_message')->insert([
            'member_id' => $memberId, 'scene' => $scene, 'title' => mb_substr($title, 0, 200), 'content' => $content,
            'link_type' => (string) $link[0], 'link_value' => mb_substr((string) $link[1], 0, 64), 'is_read' => 0, 'created_at' => now(),
        ]);
    }

    /** 我的消息，新的在前 */
    public function list(int $memberId, int $page, int $size): array
    {
        $p = DB::table('member_message')->where('member_id', $memberId)->orderByDesc('id')
            ->paginate(min(max($size, 1), 50), ['id', 'scene', 'title', 'content', 'link_type', 'link_value', 'is_read', 'created_at'], 'page', max($page, 1));
        $p->through(fn ($r) => [
            'id' => (int) $r->id, 'scene' => $r->scene, 'title' => $r->title, 'content' => $r->content,
            'link_type' => $r->link_type, 'link_value' => $r->link_value, 'is_read' => (int) $r->is_read === 1, 'created_at' => $r->created_at,
        ]);

        return Paginate::format($p) + ['unread' => $this->unreadCount($memberId)];
    }

    public function unreadCount(int $memberId): int
    {
        return DB::table('member_message')->where('member_id', $memberId)->where('is_read', 0)->count();
    }

    /** 标为已读；别人的消息当作不存在 */
    public function read(int $memberId, int $id): void
    {
        $this->mine($memberId, $id);
        DB::table('member_message')->where('id', $id)->where('is_read', 0)->update(['is_read' => 1, 'read_at' => now()]);
    }

    public function readAll(int $memberId): void
    {
        DB::table('member_message')->where('member_id', $memberId)->where('is_read', 0)->update(['is_read' => 1, 'read_at' => now()]);
    }

    public function delete(int $memberId, int $id): void
    {
        $this->mine($memberId, $id);
        DB::table('member_message')->where('id', $id)->delete();
    }

    /** 定时任务调用：删掉超过保留期的消息，返回删了多少条 */
    public function cleanup(int $days = self::KEEP_DAYS): int
    {
        return DB::table('member_message')->where('created_at', '<', now()->subDays($days))->delete();
    }

    private function mine(int $memberId, int $id): void
    {
        if (!DB::table('member_message')->where('id', $id)->where('member_id', $memberId)->exists()) {
            throw new BusinessException('消息不存在');
        }
    }
}
