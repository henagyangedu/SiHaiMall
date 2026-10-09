<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class Paginate
{
    public static function format(LengthAwarePaginator $p): array
    {
        return [
            'list'  => $p->items(),
            'total' => $p->total(),
            'page'  => $p->currentPage(),
            'size'  => $p->perPage(),
        ];
    }
}
