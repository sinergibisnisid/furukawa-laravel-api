<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TracingStockOpname extends Model
{
    protected $table = 'tracing_stocks_opname';

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'date',
        'feature',
        'adjust_in',
        'adjust_out',
        'stock_opname',
    ];

    protected $casts = [
        'date' => 'date',
        'adjust_in' => 'float',
        'adjust_out' => 'float',
        'stock_opname' => 'float',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
