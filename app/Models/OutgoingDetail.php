<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutgoingDetail extends Model
{
    protected $table = 'outgoings_detail';

    public $timestamps = false;

    protected $fillable = [
        'outgoing_id',
        'item_id',
        'quantity',
        'amount',
        'remainder_quantity',
        'production_id',
        'item_series',
    ];

    protected $casts = [
        'quantity' => 'float',
        'amount' => 'float',
        'remainder_quantity' => 'float',
    ];

    public function outgoing(): BelongsTo
    {
        return $this->belongsTo(Outgoing::class, 'outgoing_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'production_id');
    }

    public function incomingLinks(): HasMany
    {
        return $this->hasMany(OutgoingDetailIncoming::class, 'outgoing_detail_id');
    }
}
