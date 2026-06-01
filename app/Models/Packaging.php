<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Packaging extends Model
{
    use SoftDeletes;

    protected $table = 'packagings';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'description',
        'created_by',
        'updated_by',
        'deleted_by',
    ];
}
