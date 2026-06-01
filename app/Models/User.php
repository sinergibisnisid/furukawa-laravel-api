<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Model User.
 *
 * Schema mempertahankan kolom `password` (plaintext lama dari go-furukawa-api)
 * plus kolom baru `password_hash`. Login flow lazy-migrate: kalau plaintext
 * lama match saat login pertama, langsung di-hash ke `password_hash` dan
 * kolom plaintext dikosongkan.
 *
 * getAuthPassword() di-override supaya pakai password_hash.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public $timestamps = true;

    protected $fillable = [
        'username',
        'email',
        'password',
        'password_hash',
        'password_migrated_at',
        'must_change_password',
        'user_group_id',
    ];

    protected $hidden = [
        'password',
        'password_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password_migrated_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash ?: '';
    }

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }

    /**
     * Daftar nama permission user saat ini (flat).
     */
    public function permissionNames(): array
    {
        $this->loadMissing('userGroup.permissions');

        return $this->userGroup
            ? $this->userGroup->permissions->pluck('name')->all()
            : [];
    }
}
