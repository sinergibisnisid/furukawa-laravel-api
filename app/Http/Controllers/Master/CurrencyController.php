<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\GenericCrudController;
use App\Models\Currency;

class CurrencyController extends GenericCrudController
{
    protected string $modelClass = Currency::class;

    protected string $moduleName = 'Currency';

    protected array $searchable = ['name', 'description'];

    protected function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'exchange_rate' => ['required', 'numeric'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'exchange_rate' => ['sometimes', 'required', 'numeric'],
        ];
    }
}
