<?php

namespace App\Services\Common;

use App\Models\Member;
use App\Support\Exceptions\BusinessException;

/** 后台按“会员 ID / 账号 / 手机号 / 邮箱”批量找会员（发优惠券、群发消息共用） */
class MemberLookupService
{
    /**
     * 每一项是会员 ID、账号、手机号或邮箱；只认状态正常的会员。有一个找不到就整批报错，不会只处理一部分。
     * @param string[] $accounts
     * @return int[] 去重后的会员 ID
     */
    public function idsByAccounts(array $accounts): array
    {
        $ids = [];
        $missing = [];
        foreach ($accounts as $a) {
            $m = Member::query()->where(function ($q) use ($a) {
                $q->where('account', $a)->orWhere('email', $a)->orWhere('phone', $a);
                if (ctype_digit($a) && strlen($a) < 10) {
                    $q->orWhere('id', (int) $a);
                }
            })->first(['id', 'status']);
            if (!$m || (int) $m->status !== 1) {
                $missing[] = $a;
            } else {
                $ids[(int) $m->id] = true;
            }
        }
        if ($missing !== []) {
            throw new BusinessException('找不到这些会员（或已被禁用）：' . implode('、', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' 等 ' . count($missing) . ' 个' : ''));
        }

        return array_keys($ids);
    }

    /** 把输入整理成不重复、不为空的列表 */
    public function clean(array $accounts): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $accounts), fn ($v) => $v !== '')));
    }
}
