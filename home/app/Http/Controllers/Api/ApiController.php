<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\Request;

/** C 端控制器基类：只提供取当前会员 / 语言 / 币种的小工具，不含任何业务 */
abstract class ApiController extends Controller
{
    protected function member(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    protected function memberId(Request $request): int
    {
        return (int) $this->member($request)->id;
    }

    protected function lang(Request $request): string
    {
        return (string) $request->attributes->get('lang');
    }

    protected function currency(Request $request): string
    {
        return (string) $request->attributes->get('currency');
    }

    /** 来源端：h5 / mp / app / pc，不在白名单则记为 h5 */
    protected function source(Request $request): string
    {
        $s = strtolower((string) $request->header('X-Source'));

        return in_array($s, ['h5', 'mp', 'app', 'pc'], true) ? $s : 'h5';
    }
}
