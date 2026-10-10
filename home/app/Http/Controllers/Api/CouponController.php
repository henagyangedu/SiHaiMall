<?php

namespace App\Http\Controllers\Api;

use App\Services\Common\CouponService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CouponController extends ApiController
{
    public function __construct(private CouponService $service) {}

    /** 领券中心：现在能领的券。不用登录；登录后带上自己领了几张。传 goods_id 时只返回这个商品能用的 */
    public function index(Request $request)
    {
        $member = $request->attributes->get('member');
        $goodsId = (int) $request->query('goods_id', 0);

        return ApiResponse::ok($this->service->receivable($member ? (int) $member->id : null, $goodsId > 0 ? $goodsId : null));
    }

    /** 领一张 */
    public function receive(Request $request)
    {
        return ApiResponse::ok($this->service->receive($this->memberId($request), (int) $request->route('id')), '领取成功');
    }

    /** 我的优惠券。status：unused / used / expired */
    public function mine(Request $request)
    {
        $status = (string) $request->query('status', 'unused');

        return ApiResponse::ok($this->service->mine(
            $this->memberId($request), in_array($status, ['used', 'expired'], true) ? $status : 'unused',
            (int) $request->query('page', 1), (int) $request->query('size', 10)
        ));
    }
}
