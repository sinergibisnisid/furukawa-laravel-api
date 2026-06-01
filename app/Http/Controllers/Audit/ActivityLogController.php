<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function findAll(Request $request): JsonResponse
    {
        $rows = ActivityLog::orderBy('id', 'desc')->limit(500)->get();

        return ApiResponse::success($rows);
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = ActivityLog::query();

        $paginator = Paginator::apply(
            $query,
            $request,
            ['user_email', 'activity_type', 'activity_name', 'activity_description'],
        );

        return ApiResponse::paginated($paginator);
    }
}
