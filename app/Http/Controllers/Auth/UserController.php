<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(private ActivityLogService $logSvc) {}

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = User::with('userGroup');
        $paginator = Paginator::apply($query, $request, ['username', 'email']);

        return ApiResponse::paginated($paginator);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'user_group_id' => ['required', 'integer', 'exists:user_groups,id'],
        ]);

        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => '', // legacy column kept empty
            'password_hash' => Hash::make($data['password']),
            'password_migrated_at' => now(),
            'user_group_id' => $data['user_group_id'],
        ]);

        $this->logSvc->log($request, ActivityLog::TYPE_CREATE, 'User', "Created User #{$user->id} ({$user->email})");

        return ApiResponse::created($user->load('userGroup'));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'username' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'user_group_id' => ['sometimes', 'required', 'integer', 'exists:user_groups,id'],
        ]);

        $user = User::findOrFail($data['id']);
        $user->fill(array_intersect_key($data, array_flip(['username', 'email', 'user_group_id'])));

        if (! empty($data['password'])) {
            $user->password = '';
            $user->password_hash = Hash::make($data['password']);
            $user->password_migrated_at = now();
            $user->must_change_password = false;
        }

        $user->save();

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'User', "Updated User #{$user->id}");

        return ApiResponse::success($user->load('userGroup'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        // Revoke semua token sebelum delete.
        $user->tokens()->delete();
        $user->delete();

        $this->logSvc->log($request, ActivityLog::TYPE_DELETE, 'User', "Deleted User #{$id}");

        return ApiResponse::success(null, 'Deleted');
    }

    /**
     * POST /api/users/{id}/reset-password — admin-only.
     * Generate password sementara dan paksa user ganti pas login berikutnya.
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::findOrFail($id);
        $user->forceFill([
            'password' => '',
            'password_hash' => Hash::make($data['new_password']),
            'password_migrated_at' => now(),
            'must_change_password' => true,
        ])->save();

        // Invalidate all sessions.
        $user->tokens()->delete();

        $this->logSvc->log(
            $request,
            ActivityLog::TYPE_UPDATE,
            'User',
            "Admin reset password for user #{$id}",
        );

        return ApiResponse::success(null, 'Password reset; user must change password on next login');
    }
}
