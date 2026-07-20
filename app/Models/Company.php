<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $table = 'companies';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'tax_number_id',
        'address',
        'country',
        'fax_number',
        'telephone',
        'currency',
        'is_internal',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function companyTypes(): BelongsToMany
    {
        return $this->belongsToMany(
            CompanyType::class,
            'company_type_links',
            'company_id',
            'company_type_id',
        );
    }

    public function incomings(): HasMany
    {
        return $this->hasMany(Incoming::class, 'company_id');
    }

    public function outgoings(): HasMany
    {
        return $this->hasMany(Outgoing::class, 'company_id');
    }
}
