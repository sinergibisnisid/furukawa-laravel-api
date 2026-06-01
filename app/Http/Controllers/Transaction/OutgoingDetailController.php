<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\OutgoingDetailService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutgoingDetailController extends Controller
{
    public function __construct(private OutgoingDetailService $service) {}

    public function findAllPagination(Request $request, ?int $outgoingId = null): JsonResponse
    {
        return ApiResponse::paginated($this->service->findAllPagination($request, $outgoingId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outgoing_id' => ['required', 'integer', 'exists:outgoings,id'],
            'production_id' => ['nullable', 'integer', 'exists:productions,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'amount' => ['nullable', 'numeric'],
            'item_series' => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($data['production_id']) && empty($data['item_id'])) {
            return ApiResponse::error('production_id or item_id is required', 422);
        }

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
            'id' => ['required', 'integer', 'exists:outgoings_detail,id'],
            'amount' => ['sometimes', 'nullable', 'numeric'],
            'item_series' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'numeric'],
        ]);

        return ApiResponse::success($this->service->update($request, $data));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($request, $id);

        return ApiResponse::success(null, 'Deleted');
    }
}
