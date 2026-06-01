<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\OutgoingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutgoingController extends Controller
{
    public function __construct(private OutgoingService $service) {}

    public function findAll(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->findAll($request->query('feature')));
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->service->findAllPagination($request));
    }

    public function dependency(): JsonResponse
    {
        return ApiResponse::success($this->service->fetchDependency());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outgoing_no' => ['nullable', 'string', 'max:255'],
            'no' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'currency' => ['nullable', 'string', 'max:255'],
            'outgoing_date' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
            'feature' => ['required', 'string', 'max:255'],
            'outgoing_type' => ['nullable', 'string', 'max:255'],
            'peb_no' => ['nullable', 'string', 'max:255'],
            'peb_date' => ['nullable', 'date'],
            'application_number' => ['nullable', 'string', 'max:255'],
            'application_registration_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'registration_date' => ['nullable', 'date'],
            'office_code_id' => ['nullable', 'integer', 'exists:office_codes,id'],
            'total_quantity' => ['nullable', 'numeric'],
            'item_series' => ['nullable', 'string', 'max:255'],
            'travel_letter_number' => ['nullable', 'string', 'max:255'],
            'travel_letter_date' => ['nullable', 'date'],
            'order_id' => ['nullable', 'array'],
            'order_id.*' => ['integer', 'exists:orders,id'],
        ]);

        return ApiResponse::created($this->service->create($request, $data));
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        $resolvedId = $id ?? (int) $request->input('id');
        if (! $resolvedId) {
            return ApiResponse::error('id is required', 422);
        }
        $request->merge(['id' => $resolvedId]);

        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:outgoings,id'],
            'outgoing_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:255'],
            'outgoing_date' => ['sometimes', 'nullable', 'date'],
            'date' => ['sometimes', 'nullable', 'date'],
            'feature' => ['sometimes', 'required', 'string', 'max:255'],
            'outgoing_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'peb_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'peb_date' => ['sometimes', 'nullable', 'date'],
            'application_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'application_registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'registration_date' => ['sometimes', 'nullable', 'date'],
            'office_code_id' => ['sometimes', 'nullable', 'integer', 'exists:office_codes,id'],
            'total_quantity' => ['sometimes', 'nullable', 'numeric'],
            'item_series' => ['sometimes', 'nullable', 'string', 'max:255'],
            'travel_letter_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'travel_letter_date' => ['sometimes', 'nullable', 'date'],
            'order_id' => ['sometimes', 'nullable', 'array'],
            'order_id.*' => ['integer', 'exists:orders,id'],
        ]);

        return ApiResponse::success($this->service->update($request, $data));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($request, $id);

        return ApiResponse::success(null, 'Deleted');
    }
}
