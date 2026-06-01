<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfficeCode extends Model
{
    use SoftDeletes;

    protected $table = 'office_codes';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'created_by',
        'updated_by',
        'deleted_by',
    ];
}
