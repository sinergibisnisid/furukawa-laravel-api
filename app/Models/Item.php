<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $table = 'items';

    public $timestamps = true;

    protected $fillable = [
        'currency',
        'code',
        'part_no',
        'name',
        'type',
        'uom',
        'price',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'price' => 'float',
    ];
}
