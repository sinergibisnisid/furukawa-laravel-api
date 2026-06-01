<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Parser pagination + search standar.
 *
 * Baca query param `page`, `per_page` (atau `pageSize`), dan `search`,
 * apply ke Eloquent builder. Return paginator yang dipakai
 * ApiResponse::paginated() untuk envelope FE.
 */
class Paginator
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, string>  $searchableColumns
     */
    public static function apply(
        Builder $query,
        Request $request,
        array $searchableColumns = [],
        ?string $orderBy = null,
        string $orderDir = 'desc',
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', $request->query('pageSize', 10));
        if ($perPage <= 0 || $perPage > 1000) {
            $perPage = 10;
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && $searchableColumns) {
            $query->where(function (Builder $w) use ($search, $searchableColumns) {
                foreach ($searchableColumns as $col) {
                    $w->orWhere($col, 'like', '%'.$search.'%');
                }
            });
        }

        if ($orderBy) {
            $query->orderBy($orderBy, $orderDir);
        } else {
            // Default order id desc untuk hasil yang stabil antar call.
            $query->orderBy(($query->getModel()->getKeyName() ?: 'id'), 'desc');
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }
}
