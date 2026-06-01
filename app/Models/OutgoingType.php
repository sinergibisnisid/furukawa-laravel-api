<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutgoingType extends Model
{
    protected $table = 'outgoing_types';

    public $timestamps = false;

    protected $fillable = ['name'];
}
