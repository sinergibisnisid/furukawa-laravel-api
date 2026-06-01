<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    protected $table = 'stocks_opname';

    public $timestamps = false;

    protected $fillable = ['no', 'date', 'feature'];

    protected $casts = [
        'date' => 'date',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(StockOpnameDetail::class, 'stock_opname_id');
    }
}
