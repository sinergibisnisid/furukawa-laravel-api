<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\IncomingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomingController extends Controller
{
    public function __construct(private IncomingService $service) {}

    public function findAll(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->findAll($request->query('feature')));
    }

    public function findAllPagination(Request $request, ?string $featureName = null): JsonResponse
    {
        $feature = $featureName ?: $request->query('feature');

        return ApiResponse::paginated($this->service->findAllPagination($request, $feature));
    }

    public function dependency(): JsonResponse
    {
        return ApiResponse::success($this->service->fetchDependency());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'currency' => ['required', 'string', 'max:255'],
            'invoice_number' => ['required', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
            'incoming_date' => ['nullable', 'date'],
            'customs_document_number' => ['nullable', 'string', 'max:255'],
            'customs_document_date' => ['nullable', 'date'],
            'amount_item' => ['nullable', 'numeric'],
            'application_number' => ['nullable', 'string', 'max:255'],
            'office_code_id' => ['nullable', 'integer', 'exists:office_codes,id'],
            'feature' => ['required', 'string', 'max:255'],
            'is_subcontract' => ['nullable', 'boolean'],
            'order_id' => ['nullable', 'array'],
            'order_id.*' => ['integer', 'exists:orders,id'],
        ]);

        return ApiResponse::created($this->service->create($request, $data));
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        // Resolve id dulu: FE lama kirim di body, FE baru di path.
        $resolvedId = $id ?? (int) $request->input('id');
        if (! $resolvedId) {
            return ApiResponse::error('id is required', 422);
        }
        $request->merge(['id' => $resolvedId]);

        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:incomings,id'],
            'no' => ['sometimes', 'required', 'string', 'max:255'],
            'company_id' => ['sometimes', 'required', 'integer', 'exists:companies,id'],
            'currency' => ['sometimes', 'required', 'string', 'max:255'],
            'invoice_number' => ['sometimes', 'required', 'string', 'max:255'],
            'invoice_date' => ['sometimes', 'nullable', 'date'],
            'date' => ['sometimes', 'nullable', 'date'],
            'incoming_date' => ['sometimes', 'nullable', 'date'],
            'customs_document_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customs_document_date' => ['sometimes', 'nullable', 'date'],
            'amount_item' => ['sometimes', 'nullable', 'numeric'],
            'application_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'office_code_id' => ['sometimes', 'nullable', 'integer', 'exists:office_codes,id'],
            'feature' => ['sometimes', 'required', 'string', 'max:255'],
            'is_subcontract' => ['sometimes', 'nullable', 'boolean'],
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
