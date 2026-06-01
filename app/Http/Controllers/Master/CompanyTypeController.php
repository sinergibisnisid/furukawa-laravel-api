<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\GenericCrudController;
use App\Models\CompanyType;

class CompanyTypeController extends GenericCrudController
{
    protected string $modelClass = CompanyType::class;

    protected string $moduleName = 'CompanyType';

    protected array $searchable = ['name'];

    protected function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
