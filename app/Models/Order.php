<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $fillable = [
        'currency',
        'company_id',
        'no',
        'date',
        'feature',
        'terms',
        'incoming_id',
        'outgoing_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function incoming(): BelongsTo
    {
        return $this->belongsTo(Incoming::class, 'incoming_id');
    }

    public function outgoing(): BelongsTo
    {
        return $this->belongsTo(Outgoing::class, 'outgoing_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(OrderDetail::class, 'order_id');
    }
}
