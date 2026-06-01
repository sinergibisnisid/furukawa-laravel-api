<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\GenericCrudController;
use App\Models\BCLKTRawMaterial;

class BCLKTRawMaterialController extends GenericCrudController
{
    protected string $modelClass = BCLKTRawMaterial::class;

    protected string $moduleName = 'BCLKT Raw Material';

    protected array $searchable = ['bb_no', 'bb_serial_number', 'bb_registration_number'];

    protected function createRules(): array
    {
        return [
            'bb_no' => ['required', 'string', 'max:255'],
            'bb_serial_number' => ['required', 'string', 'max:255'],
            'bb_application_number' => ['required', 'string', 'max:255'],
            'bb_registration_number' => ['required', 'string', 'max:255'],
            'bb_registration_date' => ['required', 'date'],
            'bb_office_code' => ['required', 'string', 'max:255'],
            'bb_item_series' => ['nullable', 'string', 'max:255'],
            'bb_quantity' => ['required', 'numeric'],
            'bb_waste_percentage' => ['nullable', 'numeric'],
            'bb_waste_physical_form' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'bb_no' => ['sometimes', 'required', 'string', 'max:255'],
            'bb_serial_number' => ['sometimes', 'required', 'string', 'max:255'],
            'bb_application_number' => ['sometimes', 'required', 'string', 'max:255'],
            'bb_registration_number' => ['sometimes', 'required', 'string', 'max:255'],
            'bb_registration_date' => ['sometimes', 'required', 'date'],
            'bb_office_code' => ['sometimes', 'required', 'string', 'max:255'],
            'bb_item_series' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bb_quantity' => ['sometimes', 'required', 'numeric'],
            'bb_waste_percentage' => ['sometimes', 'nullable', 'numeric'],
            'bb_waste_physical_form' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
