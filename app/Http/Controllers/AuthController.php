<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use App\Exceptions\AppException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private ActivityLogService $logSvc) {}

    /**
     * POST /api/authentication/login
     *
     * Kompatibel dengan Go API lama:
     *   - Terima `user_identifier` (utama) atau `email`/`username`.
     *   - Response: { token, user, permissions } di field `data`.
     *   - Lazy-migrate password plaintext lama ke bcrypt saat login pertama
     *     berhasil (kalau APP_PASSWORD_LAZY_MIGRATE=true).
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_identifier' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'username' => ['nullable', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $identifier = $data['user_identifier']
            ?? $data['email']
            ?? $data['username']
            ?? null;

        if (! $identifier) {
            throw AppException::badRequest('user_identifier (or email/username) is required');
        }

        $user = User::query()
            ->with('userGroup.permissions')
            ->where(function ($q) use ($identifier) {
                $q->where('email', $identifier)
                    ->orWhere('username', $identifier);
            })
            ->first();

        if (! $user) {
            throw AppException::unauthorized('Invalid credentials');
        }

        $authenticated = $this->verifyAndMaybeMigratePassword($user, $data['password']);

        if (! $authenticated) {
            throw AppException::unauthorized('Invalid credentials');
        }

        // Issue Sanctum token. Expiry diatur lewat config/sanctum.expiration.
        $token = $user->createToken('web', ['*'])->plainTextToken;

        $this->logSvc->log($request, ActivityLog::TYPE_LOGIN, 'User', "User {$user->email} logged in.");

        return ApiResponse::success([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'user_group_id' => $user->user_group_id,
                'user_group' => $user->userGroup?->only(['id', 'name']),
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'permissions' => $user->permissionNames(),
        ], 'Login successful');
    }

    /**
     * POST /api/authentication/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            // Sanctum: revoke hanya token yang dipakai authenticate.
            $request->user()->currentAccessToken()?->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_LOGOUT,
                'User',
                "User {$user->email} logged out.",
            );
        }

        return ApiResponse::success(null, 'Logout successful');
    }

    /**
     * GET /api/me
     * Returns current authenticated user + permissions.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('userGroup.permissions');

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'user_group_id' => $user->user_group_id,
                'user_group' => $user->userGroup?->only(['id', 'name']),
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'permissions' => $user->permissionNames(),
        ]);
    }

    /**
     * POST /api/me/change-password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'different:current_password'],
        ]);

        $user = $request->user();

        if (! $this->verifyAndMaybeMigratePassword($user, $data['current_password'])) {
            throw AppException::unauthorized('Current password is incorrect');
        }

        $user->forceFill([
            'password' => '',
            'password_hash' => Hash::make($data['new_password']),
            'password_migrated_at' => now(),
            'must_change_password' => false,
        ])->save();

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'User', "User {$user->email} changed their password.");

        return ApiResponse::success(null, 'Password changed');
    }

    /**
     * Verifikasi password terhadap bcrypt hash atau plaintext lama.
     * Kalau plaintext lama match, langsung hash dan simpan ke password_hash.
     */
    private function verifyAndMaybeMigratePassword(User $user, string $plain): bool
    {
        // Already migrated → standard bcrypt verify.
        if (! empty($user->password_hash)) {
            return Hash::check($plain, $user->password_hash);
        }

        // Lazy migration disabled → no plaintext fallback.
        if (! config('app.password_lazy_migrate', env('PASSWORD_LAZY_MIGRATE', true))) {
            return false;
        }

        if ((string) $user->password === '') {
            return false;
        }

        // Kolom plaintext lama. Pakai hash_equals untuk constant-time compare.
        if (! hash_equals((string) $user->password, $plain)) {
            return false;
        }

        // Promote ke bcrypt. forceFill bypass mass-assignment check.
        try {
            DB::transaction(function () use ($user, $plain) {
                $fresh = User::lockForUpdate()->find($user->id);
                if (! $fresh) {
                    return;
                }
                if (! empty($fresh->password_hash)) {
                    return; // raced with another login; nothing to do
                }
                $fresh->forceFill([
                    'password' => '',
                    'password_hash' => Hash::make($plain),
                    'password_migrated_at' => now(),
                ])->save();
            });
            $user->refresh();
        } catch (\Throwable $e) {
            // Log warn tapi jangan gagalkan login. Auth-nya sudah sukses,
            // migration ini opportunistic.
            \Log::warning('Lazy password migration failed: '.$e->getMessage(), [
                'user_id' => $user->id,
            ]);
        }

        return true;
    }
}
