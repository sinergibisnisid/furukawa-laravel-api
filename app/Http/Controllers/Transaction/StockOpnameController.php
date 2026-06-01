<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\GenericCrudController;
use App\Models\StockOpname;

class StockOpnameController extends GenericCrudController
{
    protected string $modelClass = StockOpname::class;

    protected string $moduleName = 'StockOpname';

    protected array $searchable = ['no', 'feature'];

    protected function createRules(): array
    {
        return [
            'no' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'feature' => ['required', 'string', 'max:255'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'no' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date'],
            'feature' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
