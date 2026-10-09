<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MemberAdjustBalanceRequest;
use App\Http\Requests\Admin\MemberAdjustPointsRequest;
use App\Http\Requests\Admin\MemberPasswordRequest;
use App\Http\Requests\Admin\MemberUpdateRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\MemberService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MemberController extends BaseAdminController
{
    private const FILTERS = ['keyword', 'level_id', 'status', 'source', 'date_from', 'date_to', 'size'];

    public function __construct(private MemberService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(self::FILTERS)));
    }

    public function show(int $id)
    {
        return ApiResponse::ok($this->service->detail($id));
    }

    public function update(MemberUpdateRequest $request, int $id)
    {
        $this->service->update($id, $request->validated());

        return ApiResponse::ok();
    }

    public function status(StatusRequest $request, int $id)
    {
        $this->service->setStatus($id, (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    public function password(MemberPasswordRequest $request, int $id)
    {
        $this->service->resetPassword($id, (string) $request->validated('password'));

        return ApiResponse::ok(null, '密码已重置');
    }

    public function points(MemberAdjustPointsRequest $request, int $id)
    {
        $v = $request->validated();
        $after = $this->service->adjustPoints($id, (int) $v['delta'], (string) $v['remark'], (int) $this->admin($request)->id);

        return ApiResponse::ok(['points' => $after], '积分已调整');
    }

    public function balance(MemberAdjustBalanceRequest $request, int $id)
    {
        $v = $request->validated();
        $after = $this->service->adjustBalance($id, (string) $v['amount'], (string) $v['remark'], (int) $this->admin($request)->id);

        return ApiResponse::ok(['balance' => $after], '余额已调整');
    }

    public function balanceLog(Request $request, int $id)
    {
        return ApiResponse::ok($this->service->balanceLog($id, $request->only(['size'])));
    }

    /** CSV 流式导出（UTF-8 带 BOM，Excel 可直接打开）。注意：单元格以 = + - @ 开头时加前缀防公式注入 */
    public function export(Request $request)
    {
        $filters = $request->only(self::FILTERS);
        $file = 'members_' . date('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, MemberService::EXPORT_HEAD);
            foreach ($this->service->exportRows($filters) as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) ? "'" . $v : $v, $row));
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
