<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Services\ActivityLogService;
use App\Support\ApiResponse;
use App\Support\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Company controller. Lebih kaya dari generic CRUD karena ada relasi
 * many-to-many companyTypes.
 */
class CompanyController extends Controller
{
    public function __construct(private ActivityLogService $logSvc) {}

    public function findAll(Request $request): JsonResponse
    {
        $rows = Company::with('companyTypes')->orderBy('id', 'desc')->get();

        return ApiResponse::success($rows);
    }

    public function findAllPagination(Request $request): JsonResponse
    {
        $query = Company::with('companyTypes');
        $paginator = Paginator::apply($query, $request, ['code', 'name']);

        return ApiResponse::paginated($paginator);
    }

    public function show(int $id): JsonResponse
    {
        $row = Company::with('companyTypes')->findOrFail($id);

        return ApiResponse::success($row);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $this->validateData($request, isUpdate: false);

        $row = DB::transaction(function () use ($data, $request) {
            $row = Company::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'tax_number_id' => $data['tax_number_id'] ?? '',
                'address' => $data['address'] ?? '',
                'country' => $data['country'] ?? '',
                'fax_number' => $data['fax_number'] ?? '',
                'telephone' => $data['telephone'] ?? '',
                'currency' => $data['currency'] ?? '',
                'is_internal' => (bool) ($data['is_internal'] ?? false),
                'created_by' => $request->user()?->email,
                'updated_by' => $request->user()?->email,
            ]);

            if (! empty($data['company_type_ids'])) {
                $row->companyTypes()->sync($data['company_type_ids']);
            }

            return $row;
        });

        $this->logSvc->log($request, ActivityLog::TYPE_CREATE, 'Company', "Created Company #{$row->id}");

        return ApiResponse::created($row->load('companyTypes'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $this->validateData($request, isUpdate: true);
        $row = Company::findOrFail($id);

        DB::transaction(function () use ($row, $data, $request) {
            $row->fill(array_intersect_key($data, array_flip([
                'code', 'name', 'tax_number_id', 'address', 'country',
                'fax_number', 'telephone', 'currency', 'is_internal',
            ])));
            $row->updated_by = $request->user()?->email;
            $row->save();

            if (array_key_exists('company_type_ids', $data)) {
                $row->companyTypes()->sync($data['company_type_ids'] ?? []);
            }
        });

        $this->logSvc->log($request, ActivityLog::TYPE_UPDATE, 'Company', "Updated Company #{$id}");

        return ApiResponse::success($row->load('companyTypes'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = Company::findOrFail($id);
        $row->deleted_by = $request->user()?->email;
        $row->save();
        $row->delete();

        $this->logSvc->log($request, ActivityLog::TYPE_DELETE, 'Company', "Deleted Company #{$id}");

        return ApiResponse::success(null, 'Deleted');
    }

    private function validateData(Request $request, bool $isUpdate): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:255'],
            'name' => [$required, 'string', 'max:255'],
            'tax_number_id' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'fax_number' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:255'],
            'is_internal' => ['nullable', 'boolean'],
            'company_type_ids' => ['nullable', 'array'],
            'company_type_ids.*' => ['integer'],
        ]);
    }
}
