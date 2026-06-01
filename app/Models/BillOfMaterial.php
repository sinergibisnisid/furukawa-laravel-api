<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillOfMaterial extends Model
{
    protected $table = 'bill_of_materials';

    public $timestamps = false;

    protected $fillable = [
        'no',
        'date',
        'company_id',
        'finished_good_name',
        'feature',
        'quantity',
        'finished_good_id',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function finishedGoodItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'finished_good_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(BillOfMaterialDetail::class, 'bill_of_material_id');
    }
}
