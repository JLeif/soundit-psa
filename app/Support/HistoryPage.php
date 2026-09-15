<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class HistoryPage
{
    /** The caller supplies an already-authorized query and a fixed SQL timestamp expression. */
    public static function read($query, string $timestamp, string $source, string $scope, array $input, int $limit): array
    {
        $base = (clone $query)->reorder()->selectRaw("id, COALESCE({$timestamp}, created_at, '1970-01-01 00:00:00') as at, ? as source", [$source])->toBase();
        $outer = DB::query()->fromSub($base, 'history');
        $after = TimelineCursor::apply($outer, $input, $scope);
        $rows = $outer->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        if ($after) {
            $rows = $rows->reverse()->values();
        }

        return ['ids' => $rows->pluck('id')->all(), 'metadata' => TimelineCursor::metadata($rows, $limit, $more, $scope, $after)];
    }
}
