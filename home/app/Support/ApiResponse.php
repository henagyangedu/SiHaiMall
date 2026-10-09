<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function ok(mixed $data = null, string $msg = 'ok'): JsonResponse
    {
        return response()->json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    public static function fail(string $msg, int $code = 1, mixed $data = null): JsonResponse
    {
        return response()->json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }
}
