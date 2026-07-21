<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Helper response envelope sesuai kontrak js-furukawa-client.
 *
 * Bentuk:
 *  {
 *    "success":   bool,
 *    "message":   string,
 *    "data":      mixed|null,
 *    "pagination": { current_page, per_page, total, total_pages }|null,
 *    "error":     { code, message, details }|null
 *  }
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(string $message = 'OK'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
        ], 200);
    }

    public static function paginated(LengthAwarePaginator $paginator, string $message = 'OK'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'data' => $paginator->items(),
                'totalPages' => $paginator->lastPage(),
                'totalItems' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
            ],
            'entries' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ], 200);
    }

    public static function error(string $message, int $status = 400, mixed $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $status,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
