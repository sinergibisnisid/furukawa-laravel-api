<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomingDetail extends Model
{
    protected $table = 'incomings_details';

    public $timestamps = false;

    protected $fillable = [
        'incoming_id',
        'item_id',
        'po_quantity',
        'quantity',
        'hs_code',
        'country',
        'amount',
        'remainder_quantity',
        'item_series',
    ];

    protected $casts = [
        'po_quantity' => 'float',
        'quantity' => 'float',
        'amount' => 'float',
        'remainder_quantity' => 'float',
    ];

    public function incoming(): BelongsTo
    {
        return $this->belongsTo(Incoming::class, 'incoming_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function productionDetailLinks(): HasMany
    {
        return $this->hasMany(ProductionDetailLink::class, 'incoming_detail_id');
    }
}
