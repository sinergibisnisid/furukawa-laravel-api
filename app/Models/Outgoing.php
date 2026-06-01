<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Outgoing extends Model
{
    protected $table = 'outgoings';

    public $timestamps = false;

    protected $fillable = [
        'currency',
        'company_id',
        'outgoing_no',
        'outgoing_date',
        'feature',
        'outgoing_type',
        'peb_no',
        'peb_date',
        'application_number',
        'application_registration_number',
        'registration_number',
        'registration_date',
        'office_code_id',
        'total_quantity',
        'item_series',
        'travel_letter_number',
        'travel_letter_date',
    ];

    protected $casts = [
        'outgoing_date' => 'date',
        'peb_date' => 'date',
        'registration_date' => 'date',
        'travel_letter_date' => 'date',
        'total_quantity' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function officeCode(): BelongsTo
    {
        return $this->belongsTo(OfficeCode::class, 'office_code_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(OutgoingDetail::class, 'outgoing_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'outgoing_id');
    }
}
