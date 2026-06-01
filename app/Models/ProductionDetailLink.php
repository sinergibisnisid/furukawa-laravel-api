<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionDetailLink extends Model
{
    protected $table = 'productions_detail_links';

    public $timestamps = false;

    protected $fillable = [
        'incoming_detail_id',
        'outgoing_detail_id',
        'production_finished_good_detail_id',
        'production_detail_id',
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

    public function productionDetail(): BelongsTo
    {
        return $this->belongsTo(ProductionDetail::class, 'production_detail_id');
    }

    public function productionFinishedGoodDetail(): BelongsTo
    {
        return $this->belongsTo(ProductionDetail::class, 'production_finished_good_detail_id');
    }
}
