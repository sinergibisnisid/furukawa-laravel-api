<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Writer activity log (cross-cutting).
 *
 * Schema lama: activity_logs.user_email NOT NULL dengan FK ke users.email.
 * Writer ini tolerant: kegagalan log di-warn ke Laravel log, tidak
 * meng-cancel transaksi bisnis.
 */
class ActivityLogService
{
    public function log(
        Request $request,
        string $type,
        string $name,
        string $description,
    ): void {
        try {
            $user = $request->user();

            ActivityLog::create([
                'user_email' => $user?->email ?? 'system',
                'activity_type' => $type,
                'activity_name' => $name,
                'activity_description' => $description,
                'activity_timestamp' => now(),
                'ip_address' => substr((string) $request->ip(), 0, 45),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            // Jangan biarkan kegagalan log meng-cancel transaksi bisnis.
            Log::warning('ActivityLog write failed: '.$e->getMessage(), [
                'type' => $type,
                'name' => $name,
            ]);
        }
    }
}
