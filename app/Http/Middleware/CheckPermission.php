<?php

namespace App\Http\Middleware;

use App\Exceptions\AppException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware permission check.
 *
 * Pakai di route:
 *   Route::middleware(['auth:sanctum', 'permission:users:create'])->...
 *
 * Baca permissions user lewat relasi userGroup, return 403 kalau ada
 * permission yang kurang.
 *
 * Semua permission di list harus ada (AND, bukan OR).
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            throw AppException::unauthorized();
        }

        // Hindari hit DB tiap middleware run.
        if (! isset($user->_permissions_cache)) {
            $user->loadMissing('userGroup.permissions');
            $user->_permissions_cache = $user->userGroup
                ? $user->userGroup->permissions->pluck('name')->map(fn ($n) => strtolower($n))->all()
                : [];
        }

        $missing = [];
        foreach ($permissions as $permission) {
            if (! in_array(strtolower($permission), $user->_permissions_cache, true)) {
                $missing[] = $permission;
            }
        }

        if ($missing) {
            throw AppException::forbidden('Missing required permission(s): ' . implode(', ', $missing));
        }

        return $next($request);
    }
}
