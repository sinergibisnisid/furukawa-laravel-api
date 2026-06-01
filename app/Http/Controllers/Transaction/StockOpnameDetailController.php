<?php

namespace App\Http\Controllers\Transaction;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\ActivityLogService;
use App\Services\ExcelHelper;
use App\Services\StockOpnameDetailService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockOpnameDetailController extends Controller
{
    public function __construct(
        private StockOpnameDetailService $service,
        private ActivityLogService $logSvc,
    ) {}

    /**
     * GET /stocks-opname-detail?feature=Materials|WIP|Finished+Goods&start_date=...&end_date=...
     */
    public function calculate(Request $request): JsonResponse
    {
        $feature = (string) $request->query('feature', '');
        $start = $request->query('start_date');
        $end = $request->query('end_date');

        $rows = $this->service->calculate($feature, $start, $end);

        return ApiResponse::success($rows);
    }

    /**
     * POST /stocks-opname-detail/download
     * Body: { feature, start_date, end_date }
     */
    public function download(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'feature' => ['required', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'BeforeDate' => ['nullable', 'date'],
            'AfterDate' => ['nullable', 'date'],
        ]);

        $feature = $data['feature'];
        $start = $data['start_date'] ?? $data['BeforeDate'] ?? null;
        $end = $data['end_date'] ?? $data['AfterDate'] ?? null;

        $book = $this->service->buildDownload($feature, $start, $end);

        $filename = 'stock-opname-'.strtolower(str_replace(' ', '-', $feature)).'.xlsx';

        $this->logSvc->log(
            $request,
            ActivityLog::TYPE_DOWNLOAD,
            'Stock Opname Detail',
            "Downloaded {$feature} stock opname",
        );

        return ExcelHelper::downloadResponse($book, $filename);
    }
}
