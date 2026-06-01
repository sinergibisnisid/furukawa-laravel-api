<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillOfMaterial;
use App\Models\BillOfMaterialDetail;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bill of Materials (header + detail).
 *
 * Header CRUD plus nested detail endpoint yang dipakai FE
 * /bill-of-materials-detail.
 */
class BillOfMaterialController extends Controller
{
    public function __construct(private ActivityLogService $logSvc) {}

    public function findAll(Request $request): JsonResponse
    {
        $rows = BillOfMaterial::with([
            'company',
            'finishedGoodItem',
            'details.item',
        ])->orderBy('id', 'desc')->get();

        return ApiResponse::success($rows);
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = BillOfMaterial::with(['company', 'finishedGoodItem', 'details.item']);
        $paginator = Paginator::apply($query, $request, ['no']);

        return ApiResponse::paginated($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $row = BillOfMaterial::with(['company', 'finishedGoodItem', 'details.item'])->findOrFail($id);

        return ApiResponse::success($row);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $this->validateData($request, false);

        $row = DB::transaction(function () use ($data) {
            $row = BillOfMaterial::create([
                'no' => $data['no'],
                'date' => $data['date'],
                'company_id' => $data['company_id'] ?? null,
                'finished_good_id' => $data['finished_good_id'] ?? null,
                'finished_good_name' => $data['finished_good_name'] ?? null,
                'feature' => $data['feature'] ?? 'Finished Goods',
                'quantity' => $data['quantity'] ?? 0,
            ]);

            foreach ($data['details'] ?? [] as $d) {
                BillOfMaterialDetail::create([
                    'bill_of_material_id' => $row->id,
                    'item_id' => $d['item_id'],
                    'quantity' => (float) $d['quantity'],
                ]);
            }

            return $row;
        });

        $this->logSvc->log($request, ActivityLog::TYPE_CREATE, 'BillOfMaterial', "Created BOM #{$row->id} ({$row->no})");

        return ApiResponse::created($row->load(['company', 'finishedGoodItem', 'details.item']));
    }

    public function update(Request $request, ?int $id = null): JsonResponse
    {
        $resolvedId = $id ?? (int) $request->input('id');
        if (! $resolvedId) {
            return ApiResponse::error('id is required', 422);
        }
        $request->merge(['id' => $resolvedId]);
        $data = $this->validateData($request, true);

        $row = BillOfMaterial::findOrFail($resolvedId);

        DB::transaction(function () use ($row, $data) {
            $row->fill(array_intersect_key($data, array_flip([
                'no', 'date', 'company_id', 'finished_good_id',
                'finished_good_name', 'feature', 'quantity',
            ])));
            $row->save();

            // If detail array provided, replace wholesale.
            if (array_key_exists('details', $data)) {
                BillOfMaterialDetail::where('bill_of_material_id', $row->id)->delete();
                foreach ($data['details'] ?? [] as $d) {
                    BillOfMaterialDetail::create([
                        'bill_of_material_id' => $row->id,
                        'item_id' => $d['item_id'],
                        'quantity' => (float) $d['quantity'],
                    ]);
                }
            }
        });

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'BillOfMaterial', "Updated BOM #{$row->id}");

        return ApiResponse::success($row->load(['company', 'finishedGoodItem', 'details.item']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = BillOfMaterial::findOrFail($id);

        DB::transaction(function () use ($row) {
            BillOfMaterialDetail::where('bill_of_material_id', $row->id)->delete();
            $row->delete();
        });

        $this->logSvc->log($request, ActivityLog::TYPE_DELETE, 'BillOfMaterial', "Deleted BOM #{$id}");

        return ApiResponse::success(null, 'Deleted');
    }

    private function validateData(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'id' => [$isUpdate ? 'required' : 'nullable', 'integer'],
            'no' => [$required, 'string', 'max:255'],
            'date' => [$required, 'date'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'finished_good_id' => ['nullable', 'integer', 'exists:items,id'],
            'finished_good_name' => ['nullable', 'string', 'max:255'],
            'feature' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric'],
            'details' => ['sometimes', 'nullable', 'array'],
            'details.*.item_id' => ['required_with:details', 'integer', 'exists:items,id'],
            'details.*.quantity' => ['required_with:details', 'numeric', 'min:0'],
        ]);
    }
}
