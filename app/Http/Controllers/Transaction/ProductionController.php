<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\ProductionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionController extends Controller
{
    public function __construct(private ProductionService $service) {}

    public function findAll(Request $request): JsonResponse
    {
        return ApiResponse::success($this->service->findAll($request->query('feature')));
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->service->findAllPagination($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'bill_of_material_id' => ['required', 'integer', 'exists:bill_of_materials,id'],
            'total_quantity' => ['required', 'numeric', 'gt:0'],
            'description' => ['nullable', 'string'],
            'feature' => ['nullable', 'string', 'max:255'],
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
            'id' => ['required', 'integer', 'exists:productions,id'],
            'no' => ['sometimes', 'required', 'string', 'max:255'],
            'date' => ['sometimes', 'required', 'date'],
            'bill_of_material_id' => ['sometimes', 'required', 'integer', 'exists:bill_of_materials,id'],
            'total_quantity' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'description' => ['sometimes', 'nullable', 'string'],
            'feature' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success($this->service->update($request, $data));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($request, $id);

        return ApiResponse::success(null, 'Deleted');
    }
}
