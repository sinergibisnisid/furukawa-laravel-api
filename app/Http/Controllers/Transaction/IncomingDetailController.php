<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\IncomingDetailService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomingDetailController extends Controller
{
    public function __construct(private IncomingDetailService $service) {}

    public function findAllPagination(Request $request, ?int $incomingId = null): JsonResponse
    {
        $id = $incomingId ?: ($request->query('incoming_id') ? (int) $request->query('incoming_id') : null);

        return ApiResponse::paginated($this->service->findAllPagination($request, $id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'incoming_id' => ['required', 'integer', 'exists:incomings,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'po_quantity' => ['nullable', 'numeric'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'hs_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric'],
            'item_series' => ['nullable', 'string', 'max:255'],
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
            'id' => ['required', 'integer', 'exists:incomings_details,id'],
            'item_id' => ['sometimes', 'required', 'integer', 'exists:items,id'],
            'po_quantity' => ['sometimes', 'nullable', 'numeric'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'hs_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric'],
            'item_series' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success($this->service->update($request, $data));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($request, $id);

        return ApiResponse::success(null, 'Deleted');
    }
}
