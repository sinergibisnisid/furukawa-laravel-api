<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function findAll(Request $request): JsonResponse
    {
        return ApiResponse::success(Permission::orderBy('name')->get());
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        $paginator = Paginator::apply(Permission::query(), $request, ['name'], orderBy: 'name', orderDir: 'asc');

        return ApiResponse::paginated($paginator);
    }
}
