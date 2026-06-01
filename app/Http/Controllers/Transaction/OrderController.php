<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Order (header + detail).
 *
 * Order dual-purpose: feature='Purchase' link ke incomings,
 * feature='Sales' link ke outgoings. Kolom incoming_id/outgoing_id
 * di-isi IncomingService/OutgoingService saat parent transaksi
 * dibuat/di-update.
 */
class OrderController extends Controller
{
    public function __construct(private ActivityLogService $logSvc) {}

    public function findAll(Request $request): JsonResponse
    {
        $query = Order::with(['company', 'details.item']);
        if ($feature = $request->query('feature')) {
            $query->where('feature', $feature);
        }

        return ApiResponse::success($query->orderBy('id', 'desc')->get());
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = Order::with(['company', 'details.item']);
        if ($feature = $request->query('feature')) {
            $query->where('feature', $feature);
        }

        return ApiResponse::paginated(Paginator::apply($query, $request, ['no']));
    }

    public function create(Request $request): JsonResponse
    {
        $data = $this->validateData($request, false);

        $row = DB::transaction(function () use ($data) {
            $row = Order::create([
                'currency' => $data['currency'],
                'company_id' => $data['company_id'],
                'no' => $data['no'],
                'date' => $data['date'],
                'feature' => $data['feature'],
                'terms' => $data['terms'] ?? null,
            ]);
            foreach ($data['details'] ?? [] as $d) {
                OrderDetail::create([
                    'order_id' => $row->id,
                    'item_id' => $d['item_id'],
                    'quantity' => (float) $d['quantity'],
                    'price' => (float) $d['price'],
                ]);
            }

            return $row;
        });

        $this->logSvc->log($request, ActivityLog::TYPE_CREATE, 'Order', "Created Order #{$row->id} ({$row->no})");

        return ApiResponse::created($row->load(['company', 'details.item']));
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        $resolvedId = $id ?? (int) $request->input('id');
        if (! $resolvedId) {
            return ApiResponse::error('id is required', 422);
        }
        $request->merge(['id' => $resolvedId]);

        $data = $this->validateData($request, true);

        $row = Order::findOrFail($resolvedId);

        DB::transaction(function () use ($row, $data) {
            $row->fill(array_intersect_key($data, array_flip([
                'currency', 'company_id', 'no', 'date', 'feature', 'terms',
            ])));
            $row->save();

            if (array_key_exists('details', $data)) {
                OrderDetail::where('order_id', $row->id)->delete();
                foreach ($data['details'] ?? [] as $d) {
                    OrderDetail::create([
                        'order_id' => $row->id,
                        'item_id' => $d['item_id'],
                        'quantity' => (float) $d['quantity'],
                        'price' => (float) $d['price'],
                    ]);
                }
            }
        });

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'Order', "Updated Order #{$row->id}");

        return ApiResponse::success($row->load(['company', 'details.item']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = Order::findOrFail($id);
        DB::transaction(function () use ($row) {
            OrderDetail::where('order_id', $row->id)->delete();
            $row->delete();
        });

        $this->logSvc->log($request, ActivityLog::TYPE_DELETE, 'Order', "Deleted Order #{$id}");

        return ApiResponse::success(null, 'Deleted');
    }

    private function validateData(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'id' => [$isUpdate ? 'required' : 'nullable', 'integer'],
            'currency' => [$required, 'string', 'max:255'],
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'no' => [$required, 'string', 'max:255'],
            'date' => [$required, 'date'],
            'feature' => [$required, 'string', 'max:255'], // Purchase | Sales
            'terms' => ['nullable', 'string', 'max:255'],
            'details' => ['sometimes', 'nullable', 'array'],
            'details.*.item_id' => ['required_with:details', 'integer', 'exists:items,id'],
            'details.*.quantity' => ['required_with:details', 'numeric', 'min:0'],
            'details.*.price' => ['required_with:details', 'numeric', 'min:0'],
        ]);
    }
}
