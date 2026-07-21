<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillOfMaterialDetail;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ExcelHelper;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Standalone CRUD untuk BOM detail row.
 * Berguna kalau FE edit baris BOM tanpa kirim ulang seluruh header.
 */
class BillOfMaterialDetailController extends Controller
{
    public function __construct(
        private ActivityLogService $logSvc
    ) {}

    public function findAll(Request $request): JsonResponse
    {
        $query = BillOfMaterialDetail::with('item');
        if ($bomId = $request->query('bom_id')) {
            $query->where('bill_of_material_id', (int) $bomId);
        }

        return ApiResponse::success($query->orderBy('id')->get());
    }

    public function findAllPagination(Request $request, ?int $bomId = null): JsonResponse
    {
        $query = BillOfMaterialDetail::with('item');
        if ($bomId) {
            $query->where('bill_of_material_id', $bomId);
        } elseif ($q = $request->query('bom_id')) {
            $query->where('bill_of_material_id', (int) $q);
        }

        return ApiResponse::paginated(Paginator::apply($query, $request));
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bill_of_material_id' => ['required', 'integer', 'exists:bill_of_materials,id'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $row = BillOfMaterialDetail::create($data);

        $this->logSvc->log($request, ActivityLog::TYPE_CREATE, 'BillOfMaterialDetail', "Created BOMDetail #{$row->id}");

        return ApiResponse::created($row->load('item'));
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        $resolvedId = $id ?? (int) $request->input('id');
        if (!$resolvedId) {
            return ApiResponse::error('id is required', 422);
        }
        $request->merge(['id' => $resolvedId]);

        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:bill_of_materials_detail,id'],
            'item_id' => ['sometimes', 'required', 'integer', 'exists:items,id'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0'],
        ]);

        $row = BillOfMaterialDetail::findOrFail($resolvedId);
        $row->fill(array_intersect_key($data, array_flip(['item_id', 'quantity'])));
        $row->save();

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'BillOfMaterialDetail', "Updated BOMDetail #{$row->id}");

        return ApiResponse::success($row->load('item'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = BillOfMaterialDetail::findOrFail($id);
        $row->delete();

        $this->logSvc->log($request, ActivityLog::TYPE_DELETE, 'BillOfMaterialDetail', "Deleted BOMDetail #{$id}");

        return ApiResponse::success(null, 'Deleted');
    }

    public function download(Request $request): StreamedResponse
    {
        $query = BillOfMaterialDetail::with(['item', 'billOfMaterial'])
            ->orderBy('id', 'desc');

        if ($request->filled('before_date')) {
            $query->whereHas('billOfMaterial', function($q) use ($request) {
                $q->whereDate('date', '<=', $request->input('before_date'));
            });
        }
        if ($request->filled('after_date')) {
            $query->whereHas('billOfMaterial', function($q) use ($request) {
                $q->whereDate('date', '>=', $request->input('after_date'));
            });
        }

        $details = $query->get();

        $headers = [
            'NO', 'BOM NO', 'BOM DATE', 'ITEM CODE', 'ITEM DESCRIPTION', 'UOM', 'QUANTITY'
        ];

        $rows = [];
        $no = 1;
        foreach ($details as $detail) {
            $rows[] = [
                $no++,
                $detail->billOfMaterial?->no,
                $detail->billOfMaterial?->date,
                $detail->item?->code,
                $detail->item?->description,
                $detail->item?->uom,
                $detail->quantity,
            ];
        }

        $book = ExcelHelper::buildSimpleXlsx('BOM Details', $headers, $rows);
        $filename = 'bill-of-material-details-' . date('Y-m-d-His') . '.xlsx';

        $this->logSvc->log($request, ActivityLog::TYPE_DOWNLOAD, 'BillOfMaterialDetail', "Downloaded BOM Detail Data");

        return ExcelHelper::downloadResponse($book, $filename);
    }
}
