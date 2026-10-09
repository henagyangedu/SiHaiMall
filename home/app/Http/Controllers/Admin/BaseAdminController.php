<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;

abstract class BaseAdminController extends Controller
{
    /** 当前登录管理员（由 AdminAuth 中间件写入） */
    protected function admin(Request $request): Admin
    {
        return $request->attributes->get('admin');
    }
}
