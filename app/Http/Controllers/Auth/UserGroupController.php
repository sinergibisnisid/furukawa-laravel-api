<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\UserGroup;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserGroupController extends Controller
{
    public function __construct(private ActivityLogService $logSvc) {}

    public function findAll(Request $request): JsonResponse
    {
        $rows = UserGroup::with('permissions')->orderBy('id', 'desc')->get();

        return ApiResponse::success($rows);
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = UserGroup::with('permissions');
        $paginator = Paginator::apply($query, $request, ['name']);

        return ApiResponse::paginated($paginator);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $this->validateData($request, false);

        $row = DB::transaction(function () use ($data) {
            $row = UserGroup::create(['name' => $data['name']]);
            if (! empty($data['permission_ids'])) {
                $row->permissions()->sync($data['permission_ids']);
            }

            return $row;
        });

        $this->logSvc->log($request, ActivityLog::TYPE_CREATE, 'UserGroup', "Created UserGroup #{$row->id}");

        return ApiResponse::created($row->load('permissions'));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $this->validateData($request, true);
        $row = UserGroup::findOrFail($data['id']);

        DB::transaction(function () use ($row, $data) {
            if (isset($data['name'])) {
                $row->name = $data['name'];
                $row->save();
            }
            if (array_key_exists('permission_ids', $data)) {
                $row->permissions()->sync($data['permission_ids'] ?? []);
            }
        });

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'UserGroup', "Updated UserGroup #{$row->id}");

        return ApiResponse::success($row->load('permissions'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = UserGroup::findOrFail($id);
        DB::transaction(function () use ($row) {
            $row->permissions()->detach();
            $row->delete();
        });

        $this->logSvc->log($request, ActivityLog::TYPE_DELETE, 'UserGroup', "Deleted UserGroup #{$id}");

        return ApiResponse::success(null, 'Deleted');
    }

    private function validateData(Request $request, bool $isUpdate): array
    {
        $rules = [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ];
        if ($isUpdate) {
            $rules['id'] = ['required', 'integer', 'exists:user_groups,id'];
        }

        return $request->validate($rules);
    }
}
