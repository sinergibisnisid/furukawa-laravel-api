<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameDetail extends Model
{
    protected $table = 'stocks_opname_detail';

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'stock_opname_id',
        'beginning_balance',
        'income',
        'expense',
        'ending_balance',
        'difference',
        'adjust_in',
        'adjust_out',
        'stock_opname',
        'information',
    ];

    protected $casts = [
        'beginning_balance' => 'float',
        'income' => 'float',
        'expense' => 'float',
        'ending_balance' => 'float',
        'difference' => 'float',
        'adjust_in' => 'float',
        'adjust_out' => 'float',
        'stock_opname' => 'float',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }
}
