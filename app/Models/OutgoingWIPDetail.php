<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingWIPDetail extends Model
{
    protected $table = 'outgoings_wip_detail';

    public $timestamps = false;

    protected $fillable = [
        'outgoing_wip_id',
        'incoming_detail_id',
        'item_id',
        'quantity',
        'amount',
        'remainder_quantity',
    ];

    protected $casts = [
        'quantity' => 'float',
        'amount' => 'float',
        'remainder_quantity' => 'float',
    ];

    public function outgoingWIP(): BelongsTo
    {
        return $this->belongsTo(OutgoingWIP::class, 'outgoing_wip_id');
    }

    public function incomingDetail(): BelongsTo
    {
        return $this->belongsTo(IncomingDetail::class, 'incoming_detail_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
