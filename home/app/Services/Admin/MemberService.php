<?php

namespace App\Services\Admin;

use App\Models\Member;
use App\Models\MemberAddress;
use App\Models\MemberBalanceLog;
use App\Models\MemberLevel;
use App\Models\MemberPointLog;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MemberService
{
    private const MAX_EXPORT = 50000;

    public function __construct(private TranslationService $trans) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);

        $paginator = $this->filtered($f)->orderByDesc('id')->paginate($size);
        $levels = $this->levelNames(collect($paginator->items())->pluck('level_id')->unique()->all());

        $paginator->through(fn (Member $m) => $this->row($m, $levels));

        return Paginate::format($paginator);
    }

    public function detail(int $id): array
    {
        $m = $this->find($id);
        $levels = $this->levelNames([(int) $m->level_id]);

        $addresses = MemberAddress::query()->where('member_id', $id)
            ->orderByDesc('is_default')->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'id' => (int) $a->id, 'name' => $a->name, 'phone' => ($a->phone_area ? '+' . $a->phone_area . ' ' : '') . $a->phone,
                'country_code' => $a->country_code, 'province' => $a->province, 'city' => $a->city, 'district' => $a->district,
                'address' => $a->address, 'address2' => $a->address2, 'postcode' => $a->postcode, 'is_default' => (int) $a->is_default,
            ])->all();

        return $this->row($m, $levels) + [
            'agreed_at' => $m->agreed_at ? (string) $m->agreed_at : null,
            'gender'    => (int) $m->gender,
            'birthday'  => $m->birthday?->format('Y-m-d'),
            'phone_area' => $m->phone_area,
            'addresses' => $addresses,
            'bindings'  => DB::table('member_auth')->where('member_id', $id)->pluck('platform')->unique()->values()->all(),
        ];
    }

    public function update(int $id, array $d): void
    {
        $m = $this->find($id);

        $levelId = (int) ($d['level_id'] ?? $m->level_id);
        if (!MemberLevel::query()->whereKey($levelId)->exists()) {
            throw new BusinessException('会员等级不存在');
        }

        // 表单里清空的字段会被转成 null，这里用「键是否存在」判断，保证可以清空手机、邮箱
        $pick = fn (string $k, mixed $cur) => array_key_exists($k, $d) ? trim((string) ($d[$k] ?? '')) : $cur;
        $phoneArea = $pick('phone_area', $m->phone_area);
        $phone = $pick('phone', $m->phone);
        $email = mb_strtolower($pick('email', $m->email));

        if ($phone !== '' && Member::query()->where('id', '!=', $id)->where('phone_area', $phoneArea)->where('phone', $phone)->exists()) {
            throw new BusinessException('该手机号已被其它会员使用');
        }
        if ($email !== '' && Member::query()->where('id', '!=', $id)->where('email', $email)->exists()) {
            throw new BusinessException('该邮箱已被其它会员使用');
        }

        $m->update([
            'nickname'   => $pick('nickname', $m->nickname),
            'gender'     => (int) ($d['gender'] ?? $m->gender),
            'birthday'   => ($d['birthday'] ?? null) ?: null,
            'level_id'   => $levelId,
            'phone_area' => $phoneArea,
            'phone'      => $phone,
            'email'      => $email,
            'lang'       => (string) ($d['lang'] ?? $m->lang),
            'currency'   => (string) ($d['currency'] ?? $m->currency),
        ]);
    }

    public function setStatus(int $id, int $status): void
    {
        $this->find($id)->update(['status' => $status]);
    }

    public function resetPassword(int $id, string $password): void
    {
        $this->find($id)->update(['password' => Hash::make($password)]);
    }

    /** 调整积分：正数增加、负数扣减；行锁 + 流水，扣到负数拒绝 */
    public function adjustPoints(int $id, int $delta, string $remark, int $adminId): int
    {
        if ($delta === 0) {
            throw new BusinessException('调整数量不能为 0');
        }

        return DB::transaction(function () use ($id, $delta, $remark, $adminId) {
            $m = Member::query()->lockForUpdate()->find($id) ?? throw new BusinessException('会员不存在');
            $after = (int) $m->points + $delta;
            if ($after < 0) {
                throw new BusinessException('扣减后积分不能小于 0（当前 ' . (int) $m->points . '）');
            }
            $m->update(['points' => $after]);
            MemberPointLog::query()->create([
                'member_id' => $id, 'type' => 5, 'change_points' => $delta, 'after_points' => $after,
                'ref_no' => '', 'remark' => $remark, 'admin_id' => $adminId, 'created_at' => now(),
            ]);

            return $after;
        });
    }

    /** 调整余额（基准币种）：金额用字符串 + bcmath，避免浮点误差 */
    public function adjustBalance(int $id, string $amount, string $remark, int $adminId): string
    {
        if (!preg_match('/^-?\d{1,9}(\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) === 0) {
            throw new BusinessException('金额格式不正确，最多两位小数，且不能为 0');
        }

        return DB::transaction(function () use ($id, $amount, $remark, $adminId) {
            $m = Member::query()->lockForUpdate()->find($id) ?? throw new BusinessException('会员不存在');
            $after = bcadd((string) $m->balance, $amount, 2);
            if (bccomp($after, '0', 2) < 0) {
                throw new BusinessException('扣减后余额不能小于 0（当前 ' . $m->balance . '）');
            }
            $m->update(['balance' => $after]);
            MemberBalanceLog::query()->create([
                'member_id' => $id, 'type' => 1, 'change_amount' => bcadd($amount, '0', 2), 'after_amount' => $after,
                'ref_no' => '', 'remark' => $remark, 'admin_id' => $adminId, 'created_at' => now(),
            ]);

            return $after;
        });
    }

    public function balanceLog(int $id, array $f): array
    {
        $this->find($id);
        $size = min(max((int) ($f['size'] ?? 10), 1), 100);

        $p = MemberBalanceLog::query()->where('member_id', $id)->orderByDesc('id')->paginate($size);
        $p->through(fn ($r) => [
            'id' => (int) $r->id, 'type_text' => MemberBalanceLog::TYPE_TEXT[(int) $r->type] ?? '其它',
            'change_amount' => (string) $r->change_amount, 'after_amount' => (string) $r->after_amount,
            'remark' => $r->remark, 'created_at' => (string) $r->created_at,
        ]);

        return Paginate::format($p);
    }

    /** 导出：分批读取，最多 5 万行；返回生成器供控制器流式输出 */
    public function exportRows(array $f): \Generator
    {
        $levels = [];
        $n = 0;
        foreach ($this->filtered($f)->lazyById(1000) as $m) {
            if (++$n > self::MAX_EXPORT) {
                break;
            }
            if (!isset($levels[$m->level_id])) {
                $levels += $this->levelNames([(int) $m->level_id]);
            }
            $r = $this->row($m, $levels);
            yield [
                $r['id'], $r['nickname'], $r['account'], $r['phone'], $r['email'], $r['level_name'], $r['points'], $r['balance'],
                $r['register_source'], $r['status'] === 1 ? '正常' : '禁用', $r['last_login_at'], $r['created_at'],
            ];
        }
    }

    public const EXPORT_HEAD = ['ID', '昵称', '账号', '手机', '邮箱', '等级', '积分', '余额', '注册来源', '状态', '最后登录', '注册时间'];

    private function filtered(array $f): EloquentBuilder
    {
        $kw = trim((string) ($f['keyword'] ?? ''));

        return Member::query()
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(function ($w) use ($like, $kw) {
                    $w->where('nickname', 'like', $like)->orWhere('account', 'like', $like)
                      ->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like);
                    if (ctype_digit($kw)) {
                        $w->orWhere('id', (int) $kw);
                    }
                });
            })
            ->when(!empty($f['level_id']), fn ($q) => $q->where('level_id', (int) $f['level_id']))
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->when(!empty($f['source']), fn ($q) => $q->where('register_source', (string) $f['source']))
            ->when(!empty($f['date_from']), fn ($q) => $q->where('created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('created_at', '<=', $f['date_to'] . ' 23:59:59'));
    }

    private function row(Member $m, array $levels): array
    {
        return [
            'id'              => (int) $m->id,
            'nickname'        => $m->nickname,
            'avatar'          => $m->avatar,
            'account'         => $m->account,
            'phone'           => $m->phone === '' ? '' : ($m->phone_area ? '+' . $m->phone_area . ' ' : '') . $m->phone,
            'phone_raw'       => $m->phone,
            'email'           => $m->email,
            'level_id'        => (int) $m->level_id,
            'level_name'      => $levels[(int) $m->level_id] ?? '',
            'points'          => (int) $m->points,
            'balance'         => (string) $m->balance,
            'lang'            => $m->lang,
            'currency'        => $m->currency,
            'register_source' => $m->register_source,
            'status'          => (int) $m->status,
            'last_login_at'   => $m->last_login_at ? (string) $m->last_login_at : '',
            'last_login_ip'   => $m->last_login_ip,
            'created_at'      => $m->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<int,string> level_id => 当前语言名称 */
    private function levelNames(array $ids): array
    {
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return [];
        }
        $langs = $this->trans->fetch('member_level_lang', 'level_id', $ids, ['name']);
        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = $this->trans->resolve($langs[$id]['name'] ?? []);
        }

        return $out;
    }

    private function find(int $id): Member
    {
        return Member::query()->find($id) ?? throw new BusinessException('会员不存在');
    }
}
