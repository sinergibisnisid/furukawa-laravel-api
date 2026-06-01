<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BCLKTFinishedGood extends Model
{
    protected $table = 'bclkt_finished_goods';

    public $timestamps = false;

    protected $fillable = [
        'bj_serial_number',
        'bj_application_number',
        'bj_application_registration_number',
        'bj_registration_number',
        'bj_registration_date',
        'bj_office_code',
        'bj_item_series',
        'bj_quantity',
    ];

    protected $casts = [
        'bj_registration_date' => 'date',
        'bj_quantity' => 'float',
    ];
}
