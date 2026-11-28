<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminJsonPagination
{
    public static function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = $data['per_page'] ?? 50;
        $page = $data['page'] ?? 1;

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public static function respond(LengthAwarePaginator $results): JsonResponse
    {
        return response()->json($results->items())
            ->header('X-Pagination-Current-Page', (string) $results->currentPage())
            ->header('X-Pagination-Last-Page', (string) $results->lastPage())
            ->header('X-Pagination-Per-Page', (string) $results->perPage())
            ->header('X-Pagination-Total', (string) $results->total());
    }
}
