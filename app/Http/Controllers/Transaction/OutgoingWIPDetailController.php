<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Services\OutgoingWIPDetailService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutgoingWIPDetailController extends Controller
{
    public function __construct(private OutgoingWIPDetailService $service) {}

    public function findAllPagination(Request $request, ?int $outgoingWIPId = null): JsonResponse
    {
        return ApiResponse::paginated($this->service->findAllPagination($request, $outgoingWIPId));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outgoing_wip_id' => ['required', 'integer', 'exists:outgoings_wip,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'amount' => ['nullable', 'numeric'],
        ]);

        return ApiResponse::created($this->service->create($request, $data));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($request, $id);

        return ApiResponse::success(null, 'Deleted');
    }
}
