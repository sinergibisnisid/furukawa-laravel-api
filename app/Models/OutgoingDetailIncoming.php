<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutgoingDetailIncoming extends Model
{
    protected $table = 'outgoings_detail_incoming';

    public $timestamps = false;

    /**
     * Composite-keyed pivot: no auto-increment id.
     * Disable handling key integer default.
     */
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'incoming_detail_id',
        'outgoing_detail_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    public function incomingDetail(): BelongsTo
    {
        return $this->belongsTo(IncomingDetail::class, 'incoming_detail_id');
    }

    public function outgoingDetail(): BelongsTo
    {
        return $this->belongsTo(OutgoingDetail::class, 'outgoing_detail_id');
    }
}
