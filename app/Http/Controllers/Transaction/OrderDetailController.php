<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\OrderDetail;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderDetailController extends Controller
{
    public function __construct(private ActivityLogService $logSvc) {}

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = OrderDetail::with('item');
        if ($orderId = $request->query('order_id')) {
            $query->where('order_id', (int) $orderId);
        }

        return ApiResponse::paginated(Paginator::apply($query, $request));
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        $row = OrderDetail::create($data);

        $this->logSvc->log($request, ActivityLog::TYPE_CREATE, 'OrderDetail', "Created OrderDetail #{$row->id}");

        return ApiResponse::created($row->load('item'));
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        $resolvedId = $id ?? (int) $request->input('id');
        if (! $resolvedId) {
            return ApiResponse::error('id is required', 422);
        }
        $request->merge(['id' => $resolvedId]);

        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:orders_detail,id'],
            'item_id' => ['sometimes', 'required', 'integer', 'exists:items,id'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        $row = OrderDetail::findOrFail($resolvedId);
        $row->fill(array_intersect_key($data, array_flip(['item_id', 'quantity', 'price'])));
        $row->save();

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'OrderDetail', "Updated OrderDetail #{$row->id}");

        return ApiResponse::success($row->load('item'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = OrderDetail::findOrFail($id);
        $row->delete();

        $this->logSvc->log($request, ActivityLog::TYPE_DELETE, 'OrderDetail', "Deleted OrderDetail #{$id}");

        return ApiResponse::success(null, 'Deleted');
    }
}
