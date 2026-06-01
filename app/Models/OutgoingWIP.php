<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutgoingWIP extends Model
{
    protected $table = 'outgoings_wip';

    public $timestamps = false;

    protected $fillable = ['no', 'date', 'type'];

    protected $casts = [
        'date' => 'date',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(OutgoingWIPDetail::class, 'outgoing_wip_id');
    }
}
