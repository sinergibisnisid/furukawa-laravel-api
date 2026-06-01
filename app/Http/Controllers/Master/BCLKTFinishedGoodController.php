<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\GenericCrudController;
use App\Models\BCLKTFinishedGood;

class BCLKTFinishedGoodController extends GenericCrudController
{
    protected string $modelClass = BCLKTFinishedGood::class;

    protected string $moduleName = 'BCLKT Finished Good';

    protected array $searchable = ['bj_serial_number', 'bj_application_number', 'bj_registration_number'];

    protected function createRules(): array
    {
        return [
            'bj_serial_number' => ['required', 'string', 'max:255'],
            'bj_application_number' => ['required', 'string', 'max:255'],
            'bj_application_registration_number' => ['nullable', 'string', 'max:255'],
            'bj_registration_number' => ['required', 'string', 'max:255'],
            'bj_registration_date' => ['required', 'date'],
            'bj_office_code' => ['required', 'string', 'max:255'],
            'bj_item_series' => ['nullable', 'string', 'max:255'],
            'bj_quantity' => ['required', 'numeric'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'bj_serial_number' => ['sometimes', 'required', 'string', 'max:255'],
            'bj_application_number' => ['sometimes', 'required', 'string', 'max:255'],
            'bj_application_registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bj_registration_number' => ['sometimes', 'required', 'string', 'max:255'],
            'bj_registration_date' => ['sometimes', 'required', 'date'],
            'bj_office_code' => ['sometimes', 'required', 'string', 'max:255'],
            'bj_item_series' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bj_quantity' => ['sometimes', 'required', 'numeric'],
        ];
    }
}
