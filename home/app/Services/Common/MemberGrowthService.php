<?php

namespace App\Services\Common;

use App\Models\Member;
use App\Models\MemberLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 会员成长值与自动升级。
 *
 * - 成长值不单独存储，从积分流水算：注册奖励 + 签到 + 消费获得 - 退款扣回。
 *   花掉的积分（下单抵扣）和后台手工调整只影响积分余额，不影响成长值。
 * - 等级的“升级所需积分”（need_points）比的是成长值：达到某个启用等级的门槛就升到那一级，一次可以连升几级。
 * - 只升不降：成长值因退款减少、或后台把门槛调高，都不会把会员降下来；管理员手工指定的更高等级也不会被改低。
 */
class MemberGrowthService
{
    /** 计入成长值的积分流水类型：1 注册奖励、2 签到、3 消费获得、6 退款扣回（负数） */
    private const GROWTH_TYPES = [1, 2, 3, 6];

    /** @var Collection<int,MemberLevel>|null 启用的等级，按等级序号从低到高 */
    private ?Collection $levels = null;

    public function __construct(private TranslationService $trans) {}

    public function growth(int $memberId): int
    {
        return max(0, (int) DB::table('member_point_log')->where('member_id', $memberId)->whereIn('type', self::GROWTH_TYPES)->sum('change_points'));
    }

    /** 按当前成长值检查这个会员能不能升级；升了返回 true。在积分变动的事务里调用 */
    public function refresh(Member $member): bool
    {
        return $this->upgrade($member, $this->growth((int) $member->id));
    }

    /** 全部会员重算一遍（后台调低了门槛、新增了等级之后用；由 mall:member-level-refresh 每天执行）。返回升级人数 */
    public function refreshAll(): int
    {
        $upgraded = 0;
        // 只有成长值大于 0 的会员才可能升级，没有积分流水的不用看
        DB::table('member_point_log')->whereIn('type', self::GROWTH_TYPES)->groupBy('member_id')
            ->selectRaw('member_id, SUM(change_points) as growth')->havingRaw('SUM(change_points) > 0')
            ->orderBy('member_id')->chunk(500, function ($rows) use (&$upgraded) {
                $members = Member::query()->whereIn('id', $rows->pluck('member_id'))->get()->keyBy('id');
                foreach ($rows as $r) {
                    if (($m = $members[$r->member_id] ?? null) && $this->upgrade($m, (int) $r->growth)) {
                        $upgraded++;
                    }
                }
            });

        return $upgraded;
    }

    /**
     * 给会员自己看的等级信息。
     * @return array{growth:int,level_name:string,next_level:?array{name:string,need_points:int,remain:int}}
     */
    public function summary(Member $member, ?string $lang = null): array
    {
        $growth = $this->growth((int) $member->id);
        $current = MemberLevel::query()->find($member->level_id);
        // 下一级：序号比当前高的启用等级里最低的那个
        $next = $this->levels()->first(fn (MemberLevel $l) => (int) $l->level_no > (int) ($current->level_no ?? 0));
        $names = $this->trans->fetch('member_level_lang', 'level_id', array_filter([(int) ($current->id ?? 0), (int) ($next->id ?? 0)]), ['name']);

        return [
            'growth' => $growth,
            'level_name' => $current ? $this->trans->resolve($names[$current->id]['name'] ?? [], $lang) : '',
            'next_level' => $next ? [
                'name' => $this->trans->resolve($names[$next->id]['name'] ?? [], $lang),
                'need_points' => (int) $next->need_points, 'remain' => max(0, (int) $next->need_points - $growth),
            ] : null,
        ];
    }

    private function upgrade(Member $member, int $growth): bool
    {
        // 成长值够得着的启用等级里序号最高的
        $target = $this->levels()->filter(fn (MemberLevel $l) => (int) $l->need_points <= $growth)->last();
        if (!$target || (int) $target->id === (int) $member->level_id) {
            return false;
        }
        // 当前等级可能已被停用或删除，这时按最低算
        $currentNo = (int) MemberLevel::query()->where('id', $member->level_id)->value('level_no');
        if ((int) $target->level_no <= $currentNo) {
            return false;
        }
        $member->forceFill(['level_id' => $target->id])->save();

        return true;
    }

    private function levels(): Collection
    {
        return $this->levels ??= MemberLevel::query()->where('status', 1)->orderBy('level_no')->get();
    }
}
