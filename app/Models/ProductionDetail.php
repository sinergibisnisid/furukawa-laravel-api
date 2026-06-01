<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionDetail extends Model
{
    protected $table = 'productions_detail';

    public $timestamps = false;

    protected $fillable = [
        'production_id',
        'item_id',
        'po_quantity',
        'quantity',
        'stock_opname_feature',
        'identifier',
        'remainder_quantity',
    ];

    protected $casts = [
        'po_quantity' => 'float',
        'quantity' => 'float',
        'remainder_quantity' => 'float',
    ];

    // Identifier value untuk FIFO production logic.
    public const IDENT_CONSUME = 'CONSUME';
    public const IDENT_PRODUCE = 'PRODUCE';

    public function production(): BelongsTo
    {
        return $this->belongsTo(Production::class, 'production_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProductionDetailLink::class, 'production_detail_id');
    }
}
