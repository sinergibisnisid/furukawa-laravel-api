<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\ExcelHelper;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Placeholder untuk laporan yang menu-nya ada di FE tapi tidak pernah
 * diimplementasikan di go-furukawa-api.
 *
 * Mengembalikan list paginated kosong supaya halaman FE tidak crash.
 *
 * Daftar nama (dari js-furukawa-client/src/configs/reportConfig.js):
 *   - goods-usage-subcontract
 *   - machine-equipment-expenditure
 *   - machine-equipment-income
 *   - machine-equipment-mutation
 *   - material-expenditure
 */
class DeprecatedReportController extends Controller
{
    public function paginated(Request $request, string $name): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('size', 25);
        if ($perPage <= 0) {
            $perPage = 25;
        }

        return response()->json([
            'success' => true,
            'message' => 'OK (report not implemented)',
            'data' => [],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'total_pages' => 1,
            ],
        ]);
    }

    public function downloadJson(Request $request, string $name): JsonResponse
    {
        return ApiResponse::success(['entries' => []]);
    }

    public function downloadExcel(Request $request, string $name): StreamedResponse
    {
        $book = ExcelHelper::buildSimpleXlsx($name, ['Info'], [['Report not implemented']]);

        return ExcelHelper::downloadResponse($book, $name.'.xlsx');
    }
}
