<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Production extends Model
{
    protected $table = 'productions';

    public $timestamps = true;

    protected $fillable = [
        'no',
        'description',
        'date',
        'bill_of_material_id',
        'feature',
        'remainder_quantity',
        'total_quantity',
    ];

    protected $casts = [
        'date' => 'date',
        'remainder_quantity' => 'float',
        'total_quantity' => 'float',
    ];

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class, 'bill_of_material_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProductionDetail::class, 'production_id');
    }
}
