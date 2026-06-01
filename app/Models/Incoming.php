<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incoming extends Model
{
    protected $table = 'incomings';

    public $timestamps = false;

    protected $fillable = [
        'company_id',
        'no',
        'currency',
        'invoice_number',
        'incoming_date',
        'customs_document_number',
        'customs_document_date',
        'feature',
        'amount_item',
        'invoice_date',
        'is_subcontract',
        'application_number',
        'office_code_id',
    ];

    protected $casts = [
        'incoming_date' => 'date',
        'customs_document_date' => 'date',
        'invoice_date' => 'date',
        'amount_item' => 'float',
        'is_subcontract' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function officeCode(): BelongsTo
    {
        return $this->belongsTo(OfficeCode::class, 'office_code_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(IncomingDetail::class, 'incoming_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'incoming_id');
    }
}
