<?php

namespace App\Services\Common;

use App\Models\Member;
use App\Models\MemberPointLog;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 不靠消费得来的积分奖励：注册奖励（流水 type=1）、每日签到（type=2）。
 * 数量在交易设置里配（points_register、points_signin），填 0 就是不送 / 不开签到。
 * 这两种积分计入成长值，发放后会检查会员能不能升级（见 MemberGrowthService）。
 */
class PointRewardService
{
    private const TYPE_REGISTER = 1;

    private const TYPE_SIGNIN = 2;

    public function __construct(private ConfigService $config, private MemberGrowthService $growth) {}

    /** 新会员注册后调用。每个会员只送一次 */
    public function onRegister(Member $member): void
    {
        $points = (int) ($this->config->get('trade')['points_register'] ?? 0);
        if ($points <= 0 || MemberPointLog::query()->where('member_id', $member->id)->where('type', self::TYPE_REGISTER)->exists()) {
            return;
        }
        DB::transaction(function () use ($member, $points) {
            $m = Member::query()->lockForUpdate()->find($member->id);
            if ($m) {
                $this->grant($m, $points, self::TYPE_REGISTER, '', '注册奖励');
            }
        });
        $member->refresh();
    }

    /** @return array{enabled:bool,points:int,signed_today:bool} points 是签到一次送多少 */
    public function signInStatus(int $memberId): array
    {
        $points = $this->signInPoints();

        return ['enabled' => $points > 0, 'points' => $points, 'signed_today' => $points > 0 && $this->signedToday($memberId)];
    }

    /**
     * 签到。一天一次，“一天”按应用时区（config app.timezone）的自然日算。
     * @return array{points:int,total:int} 这次得到的积分、签到后的积分余额
     */
    public function signIn(int $memberId): array
    {
        $points = $this->signInPoints();
        if ($points <= 0) {
            throw new BusinessException('签到功能未开启');
        }

        return DB::transaction(function () use ($memberId, $points) {
            // 锁住会员这一行：同一个人同时点两次，第二次要等第一次写完流水再判断
            $m = Member::query()->lockForUpdate()->find($memberId) ?? throw new BusinessException('会员不存在');
            if ($this->signedToday($memberId)) {
                throw new BusinessException('今天已经签到过了');
            }
            $this->grant($m, $points, self::TYPE_SIGNIN, 'SIGN' . now()->format('Ymd'), '每日签到');

            return ['points' => $points, 'total' => (int) $m->points];
        });
    }

    private function signInPoints(): int
    {
        return max(0, (int) ($this->config->get('trade')['points_signin'] ?? 0));
    }

    private function signedToday(int $memberId): bool
    {
        return MemberPointLog::query()->where('member_id', $memberId)->where('type', self::TYPE_SIGNIN)
            ->where('created_at', '>=', now()->startOfDay())->exists();
    }

    private function grant(Member $m, int $points, int $type, string $refNo, string $remark): void
    {
        $after = (int) $m->points + $points;
        $m->update(['points' => $after]);
        MemberPointLog::query()->create([
            'member_id' => $m->id, 'type' => $type, 'change_points' => $points, 'after_points' => $after,
            'ref_no' => $refNo, 'remark' => $remark, 'admin_id' => 0, 'created_at' => now(),
        ]);
        $this->growth->refresh($m);
    }
}
