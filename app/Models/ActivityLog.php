<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_email',
        'activity_type',
        'activity_name',
        'activity_description',
        'activity_timestamp',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'activity_timestamp' => 'datetime',
    ];

    // Activity types matching go-furukawa-api/internal/entity/trait/activity_log_type.go
    public const TYPE_CREATE = 'CREATE';
    public const TYPE_UPDATE = 'UPDATE';
    public const TYPE_DELETE = 'DELETE';
    public const TYPE_LOGIN = 'LOGIN';
    public const TYPE_LOGOUT = 'LOGOUT';
    public const TYPE_UPLOAD = 'UPLOAD';
    public const TYPE_DOWNLOAD = 'DOWNLOAD';
}
