<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\GenericCrudController;
use App\Models\OfficeCode;

class OfficeCodeController extends GenericCrudController
{
    protected string $modelClass = OfficeCode::class;

    protected string $moduleName = 'OfficeCode';

    protected array $searchable = ['code', 'name'];

    protected function createRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'code' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
