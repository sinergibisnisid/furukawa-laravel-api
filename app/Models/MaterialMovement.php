<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialMovement extends Model
{
    protected $table = 'material_movements';

    /**
     * The schema only carries `created_at` (no updated_at). Disable Eloquent's
     * timestamping and let the column default (NOW()) handle the value.
     */
    public $timestamps = false;

    protected $fillable = [
        'movement_type',
        'movement_date',
        'document_id',
        'document_no',
        'item_id',
        'quantity',
        'movement_direction',
        'location_from',
        'location_to',
        'parent_movement_id',
        'root_incoming_material_movement_id',
        'adjustment_type',
        'created_at',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'quantity' => 'float',
        'created_at' => 'datetime',
    ];

    // Movement type, mengikuti konstanta material movement go-furukawa-api.
    public const TYPE_INCOMING_MATERIAL = 'INCOMING_MATERIAL';
    public const TYPE_OUTGOING_WIP = 'OUTGOING_WIP';
    public const TYPE_PRODUCTION_CONSUME = 'PRODUCTION_CONSUME';
    public const TYPE_PRODUCTION_PRODUCE = 'PRODUCTION_PRODUCE';
    public const TYPE_OUTGOING_FG = 'OUTGOING_FG';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    public const DIRECTION_IN = 'IN';
    public const DIRECTION_OUT = 'OUT';

    public const LOC_WAREHOUSE = 'WAREHOUSE';
    public const LOC_WIP = 'WIP';
    public const LOC_PRODUCTION = 'PRODUCTION';
    public const LOC_FG = 'FG';

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_movement_id');
    }

    public function rootIncomingMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_incoming_material_movement_id');
    }
}
