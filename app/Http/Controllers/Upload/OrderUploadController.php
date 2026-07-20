<?php

namespace App\Http\Controllers\Upload;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Services\ActivityLogService;
use App\Services\ExcelHelper;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderUploadController extends Controller
{
    public function __construct(
        private ActivityLogService $logSvc,
    ) {}

    public function upload(Request $request, string $featureName): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $file = $request->file('file');
        $book = ExcelHelper::open($file->getRealPath());
        $headerRows = ExcelHelper::readSheet($book, 0);
        $detailRows = ExcelHelper::readSheet($book, 1);

        $errors = [];
        $duplicates = [];
        $newNos = [];
        $orderDrafts = [];
        $orderNos = [];
        $headerNames = $headerRows[0] ?? [];

        // =========================
        // STEP 1: Parse sheet header
        // =========================
        foreach ($headerRows as $i => $row) {
            $rowIndex = $i + 1;
            if ($i < 2) {
                continue;
            }
            if ($this->rowEmpty($row)) {
                continue;
            }

            $dateValue = trim((string) ($row[1] ?? ''));
            $orderNo = trim((string) ($row[2] ?? ''));
            $companyCode = trim((string) ($row[3] ?? ''));
            $terms = trim((string) ($row[4] ?? ''));
            $currency = trim((string) ($row[5] ?? ''));

            if ($orderNo === '') {
                $errors[] = $this->err($rowIndex, $headerNames[2] ?? 'Order No', 'Order no wajib diisi', $orderNo);
                continue;
            }
            if (isset($newNos[$orderNo])) {
                $errors[] = $this->err($rowIndex, $headerNames[2] ?? 'Order No', 'Duplicate order no di Excel', $orderNo);
                continue;
            }

            try {
                $date = ExcelHelper::excelDateToTime($row[1] ?? null);
            } catch (AppException $e) {
                $errors[] = $this->err($rowIndex, 'Date', $e->getMessage(), $dateValue);
                continue;
            }

            $company = Company::where('code', $companyCode)->first();
            if (! $company) {
                $errors[] = $this->err($rowIndex, $headerNames[3] ?? 'Company', 'Company tidak ditemukan', $companyCode);
                continue;
            }

            if ($currency === '') {
                $errors[] = $this->err($rowIndex, $headerNames[5] ?? 'Currency', 'Currency wajib diisi', $currency);
                continue;
            }

            $newNos[$orderNo] = true;
            $orderNos[] = $orderNo;
            $orderDrafts[$orderNo] = [
                'company_id' => $company->id,
                'no' => $orderNo,
                'currency' => $currency,
                'date' => $date?->toDateString(),
                'feature' => $featureName,
                'terms' => $terms,
            ];
        }

        if ($orderNos) {
            $existing = Order::whereIn('no', $orderNos)
                ->where('feature', $featureName)
                ->pluck('no')
                ->all();
            foreach ($existing as $dupNo) {
                $duplicates[] = $dupNo;
            }
        }

        if ($errors || $duplicates) {
            return ApiResponse::success([
                'errors' => $errors,
                'duplicates' => $duplicates,
            ], 'Upload validation failed');
        }

        // =========================
        // STEP 2: Transaction insert
        // =========================
        $detailHeaderNames = $detailRows[0] ?? [];
        $detailErrors = [];

        try {
            $result = DB::transaction(function () use ($detailRows, $orderDrafts, $newNos, &$detailErrors, $detailHeaderNames, $featureName) {
                $orderModels = [];
                foreach ($orderDrafts as $no => $draft) {
                    $order = Order::create($draft);
                    $orderModels[$no] = $order;
                }

                foreach ($detailRows as $i => $row) {
                    $rowIndex = $i + 1;
                    if ($i < 2) {
                        continue;
                    }
                    if ($this->rowEmpty($row)) {
                        continue;
                    }

                    $orderNo = trim((string) ($row[1] ?? ''));
                    $itemCode = trim((string) ($row[2] ?? ''));
                    $price = (float) ($row[3] ?? 0);
                    $quantity = (float) ($row[4] ?? 0);

                    if (! isset($newNos[$orderNo])) {
                        $detailErrors[] = $this->err($rowIndex, $detailHeaderNames[1] ?? 'Order No', "Order No '$orderNo' tidak ada di Sheet 1", $orderNo);
                        continue;
                    }

                    $item = Item::where('code', $itemCode)->first();
                    if (! $item) {
                        $detailErrors[] = $this->err($rowIndex, $detailHeaderNames[2] ?? 'Item Code', 'Item tidak ditemukan', $itemCode);
                        continue;
                    }

                    if ($quantity <= 0) {
                        $detailErrors[] = $this->err($rowIndex, $detailHeaderNames[4] ?? 'Quantity', 'Quantity harus > 0', (string) $quantity);
                        continue;
                    }
                    if ($price <= 0) {
                        $detailErrors[] = $this->err($rowIndex, $detailHeaderNames[3] ?? 'Amount', 'Amount (Price) harus > 0', (string) $price);
                        continue;
                    }

                    OrderDetail::create([
                        'order_id' => $orderModels[$orderNo]->id,
                        'item_id' => $item->id,
                        'quantity' => $quantity,
                        'price' => $price,
                    ]);
                }

                if ($detailErrors) {
                    DB::rollBack();

                    return false;
                }

                return true;
            });
        } catch (Throwable $e) {
            return ApiResponse::error('Database error: '.$e->getMessage(), 500);
        }

        if ($detailErrors) {
            return ApiResponse::success([
                'errors' => $detailErrors,
                'duplicates' => [],
            ], 'Upload validation failed');
        }

        $this->logSvc->log(
            request(),
            ActivityLog::TYPE_CREATE,
            'Order Upload',
            "Uploaded " . count($orderNos) . " orders ($featureName)"
        );

        return ApiResponse::success(null, 'Data uploaded successfully');
    }

    private function err(int $row, string $col, string $msg, string $val = ''): array
    {
        return [
            'row' => $row,
            'column' => $col,
            'message' => $msg,
            'value' => $val,
        ];
    }

    private function rowEmpty(array $row): bool
    {
        foreach ($row as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }
}
