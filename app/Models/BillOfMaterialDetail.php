<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillOfMaterialDetail extends Model
{
    protected $table = 'bill_of_materials_detail';

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'bill_of_material_id',
        'quantity',
        'production_detail_id',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function productionDetail(): BelongsTo
    {
        return $this->belongsTo(ProductionDetail::class, 'production_detail_id');
    }
}
