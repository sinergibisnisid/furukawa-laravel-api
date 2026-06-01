<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\IncomingDetail;
use App\Models\ProductionDetail;
use App\Models\ProductionDetailLink;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Akses read-only ProductionDetail + trace link.
 *
 * Mutasi ProductionDetail langsung di-disable; FIFO engine yang nulis
 * waktu Production.create(). Kalau perlu ubah, lewat Production CRUD.
 */
class ProductionDetailController extends Controller
{
    public function findAllPagination(Request $request, ?int $productionId = null): JsonResponse
    {
        $id = $productionId ?: ($request->query('production_id') ? (int) $request->query('production_id') : null);

        $query = ProductionDetail::with(['item', 'links.incomingDetail.incoming']);
        if ($id) {
            $query->where('production_id', $id);
        }

        $paginator = Paginator::apply($query, $request);

        // Enrich tiap row dengan PIB trace (incoming asal -> customs doc).
        $rows = collect($paginator->items())->map(function (ProductionDetail $d) {
            $trace = $d->links->map(function (ProductionDetailLink $link) {
                $inc = $link->incomingDetail?->incoming;

                return [
                    'incoming_detail_id' => $link->incoming_detail_id,
                    'quantity' => (float) $link->quantity,
                    'incoming_no' => $inc?->no,
                    'incoming_date' => optional($inc?->incoming_date)->toDateString(),
                    'customs_document_number' => $inc?->customs_document_number,
                    'customs_document_date' => optional($inc?->customs_document_date)->toDateString(),
                    'item_series' => $link->incomingDetail?->item_series,
                ];
            })->all();

            $arr = $d->toArray();
            $arr['trace'] = $trace;

            return $arr;
        })->all();

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function findFiltered(Request $request): JsonResponse
    {
        $query = ProductionDetail::with(['item', 'production'])
            ->where('remainder_quantity', '>', 0);

        if ($feature = $request->query('feature')) {
            $query->whereHas('production', fn ($q) => $q->where('feature', $feature));
        }
        if ($itemId = $request->query('item_id')) {
            $query->where('item_id', (int) $itemId);
        }

        return ApiResponse::success($query->orderBy('id', 'desc')->limit(500)->get());
    }
}
