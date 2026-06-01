<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\GenericCrudController;
use App\Models\Item;

class ItemController extends GenericCrudController
{
    protected string $modelClass = Item::class;

    protected string $moduleName = 'Item';

    protected array $searchable = ['code', 'name', 'part_no'];

    protected function createRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', 'unique:items,code'],
            'name' => ['required', 'string', 'max:255'],
            'part_no' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:255'],
            'uom' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'code' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'part_no' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', 'string', 'max:255'],
            'uom' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric'],
        ];
    }
}
