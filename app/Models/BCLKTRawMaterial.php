<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BCLKTRawMaterial extends Model
{
    protected $table = 'bclkt_raw_materials';

    public $timestamps = false;

    protected $fillable = [
        'bb_no',
        'bb_serial_number',
        'bb_application_number',
        'bb_registration_number',
        'bb_registration_date',
        'bb_office_code',
        'bb_item_series',
        'bb_quantity',
        'bb_waste_percentage',
        'bb_waste_physical_form',
    ];

    protected $casts = [
        'bb_registration_date' => 'date',
        'bb_quantity' => 'float',
        'bb_waste_percentage' => 'float',
    ];
}
