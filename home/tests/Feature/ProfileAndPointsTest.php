<?php

namespace Tests\Feature;

use App\Models\MemberPointLog;
use App\Models\UploadFile;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** C 端个人资料（昵称、头像等）和积分明细 */
class ProfileAndPointsTest extends FeatureTestCase
{
    /** 登记一张“已上传”的头像，返回存储驱动给出的地址 */
    private function uploadedAvatar(int $memberId): string
    {
        $path = "member/{$memberId}/avatar/2026/10/09/".str_repeat('a', 32).'.png';
        UploadFile::query()->create([
            'driver' => 'local', 'scene' => 'avatar', 'path' => $path, 'name' => 'a.png', 'ext' => 'png', 'mime' => 'image/png',
            'size' => 100, 'md5' => str_repeat('0', 32), 'width' => 1, 'height' => 1, 'admin_id' => 0,
        ]);

        return app(\App\Drivers\DriverManager::class)->storage()->url($path);
    }

    public function test_profile_fields_can_be_updated(): void
    {
        [$m, $token] = $this->makeMember();

        $r = $this->api('PUT', '/member/profile', ['nickname' => ' 新昵称 ', 'gender' => 2, 'birthday' => '1990-05-06'], $token);

        $r->assertJsonPath('code', 0);
        $this->assertSame(['新昵称', 2, '1990-05-06'], [trim($r->json('data.nickname')), $r->json('data.gender'), $r->json('data.birthday')]);

        // 昵称不能清空；生日不能是未来；性别只能是 0/1/2
        $this->api('PUT', '/member/profile', ['nickname' => '   '], $token)->assertJsonPath('code', 422);
        $this->api('PUT', '/member/profile', ['birthday' => now()->addDay()->toDateString()], $token)->assertJsonPath('code', 422);
        $this->api('PUT', '/member/profile', ['gender' => 9], $token)->assertJsonPath('code', 422);
        // 生日可以清掉
        $this->api('PUT', '/member/profile', ['birthday' => null], $token)->assertJsonPath('data.birthday', null);

        // 这个接口改不了积分、余额、等级
        $this->api('PUT', '/member/profile', ['points' => 99999, 'balance' => '999', 'level_id' => 9, 'nickname' => 'x'], $token)->assertJsonPath('code', 0);
        $row = DB::table('member')->where('id', $m->id)->first();
        $this->assertSame([0, '0.00', 1], [(int) $row->points, (string) $row->balance, (int) $row->level_id]);
    }

    public function test_avatar_must_be_the_members_own_upload(): void
    {
        [$m, $token] = $this->makeMember();
        [$other] = $this->makeMember();
        $mine = $this->uploadedAvatar((int) $m->id);
        $theirs = $this->uploadedAvatar((int) $other->id);

        $this->api('PUT', '/member/profile', ['avatar' => 'https://evil.example/a.png'], $token)->assertJsonPath('code', 1);
        $this->api('PUT', '/member/profile', ['avatar' => $theirs], $token)->assertJsonPath('code', 1);
        $this->assertSame('', (string) DB::table('member')->where('id', $m->id)->value('avatar'));

        $this->api('PUT', '/member/profile', ['avatar' => $mine], $token)->assertJsonPath('data.avatar', $mine);
        // 原样带上现有头像、或者清空头像都可以
        $this->api('PUT', '/member/profile', ['avatar' => $mine, 'nickname' => '改名'], $token)->assertJsonPath('code', 0);
        $this->api('PUT', '/member/profile', ['avatar' => ''], $token)->assertJsonPath('data.avatar', '');
    }

    public function test_an_existing_external_avatar_can_be_resubmitted_unchanged(): void
    {
        // 第三方登录带来的头像不是本站上传的，资料页原样提交时不应该报错
        [$m, $token] = $this->makeMember(['avatar' => 'https://thirdparty.example/u/1.png']);

        $this->api('PUT', '/member/profile', ['avatar' => 'https://thirdparty.example/u/1.png', 'nickname' => '新名字'], $token)->assertJsonPath('code', 0);
        $this->api('PUT', '/member/profile', ['avatar' => 'https://thirdparty.example/u/2.png'], $token)->assertJsonPath('code', 1);
    }

    public function test_point_history_lists_only_my_records_newest_first_without_internal_remarks(): void
    {
        [$m, $token] = $this->makeMember(['points' => 150]);
        [$other, $otherToken] = $this->makeMember();
        $log = fn (int $memberId, int $type, int $change, int $after, string $ref = '') => MemberPointLog::query()->create([
            'member_id' => $memberId, 'type' => $type, 'change_points' => $change, 'after_points' => $after, 'ref_no' => $ref,
            'remark' => '内部备注：客服补偿', 'admin_id' => 1, 'created_at' => now(),
        ]);
        $log((int) $m->id, 5, 200, 200);
        $log((int) $m->id, 4, -100, 100, 'ORDER1');
        $log((int) $m->id, 3, 50, 150, 'ORDER1');
        $log((int) $other->id, 5, 999, 999);

        $r = $this->api('GET', '/member/points?size=2', [], $token);

        $r->assertJsonPath('code', 0);
        $this->assertSame(3, $r->json('data.total'));
        $this->assertSame([[3, 50, 150, 'ORDER1'], [4, -100, 100, 'ORDER1']], array_map(fn ($x) => [$x['type'], $x['change_points'], $x['after_points'], $x['ref_no']], $r->json('data.list')));
        $this->assertArrayNotHasKey('remark', $r->json('data.list.0'));
        $this->assertArrayNotHasKey('admin_id', $r->json('data.list.0'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $r->json('data.list.0.created_at'));

        $this->assertSame([[5, 200]], array_map(fn ($x) => [$x['type'], $x['change_points']], $this->api('GET', '/member/points?size=2&page=2', [], $token)->json('data.list')));
        $this->assertSame(1, $this->api('GET', '/member/points', [], $otherToken)->json('data.total'));
        $this->api('GET', '/member/points')->assertJsonPath('code', 401);
    }
}
