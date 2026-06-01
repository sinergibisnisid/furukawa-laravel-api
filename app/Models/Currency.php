<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Currency extends Model
{
    use SoftDeletes;

    protected $table = 'currencies';

    public $timestamps = true;

    protected $fillable = [
        'name',
        'description',
        'exchange_rate',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'exchange_rate' => 'float',
    ];
}
