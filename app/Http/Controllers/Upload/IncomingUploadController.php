<?php

namespace App\Http\Controllers\Upload;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Incoming;
use App\Models\IncomingDetail;
use App\Models\Item;
use App\Models\OfficeCode;
use App\Models\Order;
use App\Services\ActivityLogService;
use App\Services\ExcelHelper;
use App\Services\MaterialMovementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Upload Incoming 2-sheet.
 *
 * Endpoint FE:
 *   POST /incomings-detail/upload/{featureName}
 *
 * Sheet 1 = header incoming
 * Sheet 2 = detail incoming
 */
class IncomingUploadController extends Controller
{
    public function __construct(
        private MaterialMovementService $movementSvc,
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
        $incomingDrafts = [];
        $incomingNos = [];
        $headerNames = $headerRows[0] ?? [];

        // =========================
        // STEP 1: Parse sheet header
        // =========================
        foreach ($headerRows as $i => $row) {
            $rowIndex = $i + 1;
            if ($i <= 1) {
                continue;
            }
            if ($this->rowEmpty($row)) {
                continue;
            }

            $no = trim((string) ($row[1] ?? ''));
            if ($no === '') {
                $errors[] = $this->err($rowIndex, $headerNames[1] ?? 'No', 'Incoming no wajib diisi', $no);
                continue;
            }
            if (isset($newNos[$no])) {
                $errors[] = $this->err($rowIndex, $headerNames[1] ?? 'No', 'Duplicate incoming no', $no);
                continue;
            }

            try {
                $date = ExcelHelper::excelDateToTime($row[2] ?? null);
                $invoiceDate = ExcelHelper::excelDateToTime($row[8] ?? null);
                $customDate = ExcelHelper::excelDateToTime($row[10] ?? null);
            } catch (AppException $e) {
                $errors[] = $this->err($rowIndex, 'Date', $e->getMessage(), (string) ($row[2] ?? ''));
                continue;
            }

            $orderNos = array_filter(array_map('trim', explode(';', (string) ($row[3] ?? ''))));
            $orders = $orderNos
                ? Order::whereIn('no', $orderNos)->where('feature', 'Purchase')->get()
                : collect();
            if (count($orderNos) !== $orders->count()) {
                $errors[] = $this->err($rowIndex, $headerNames[3] ?? 'Order No', 'Order purchase tidak ditemukan', (string) ($row[3] ?? ''));
                continue;
            }

            $company = Company::where('code', trim((string) ($row[4] ?? '')))->first();
            if (! $company) {
                $errors[] = $this->err($rowIndex, $headerNames[4] ?? 'Company Code', 'Company tidak ditemukan', (string) ($row[4] ?? ''));
                continue;
            }

            $officeCode = OfficeCode::where('code', trim((string) ($row[12] ?? '')))->first();
            if (! $officeCode) {
                $errors[] = $this->err($rowIndex, $headerNames[12] ?? 'Office Code', 'Office code tidak ditemukan', (string) ($row[12] ?? ''));
                continue;
            }

            $newNos[$no] = true;
            $incomingNos[] = $no;
            $incomingDrafts[$no] = [
                'company_id' => $company->id,
                'no' => $no,
                'currency' => (string) ($row[6] ?? ''),
                'invoice_number' => (string) ($row[7] ?? ''),
                'incoming_date' => $date?->toDateString(),
                'invoice_date' => $invoiceDate?->toDateString(),
                'customs_document_number' => (string) ($row[9] ?? ''),
                'customs_document_date' => $customDate?->toDateString(),
                'amount_item' => (float) ($row[11] ?? 0),
                'application_number' => (string) ($row[13] ?? ''),
                'office_code_id' => $officeCode->id,
                'feature' => $featureName === 'finished-goods' ? 'Finished Goods' : 'Material',
                'is_subcontract' => false,
                'order_ids' => $orders->pluck('id')->all(),
            ];
        }

        if ($incomingNos) {
            $existing = Incoming::whereIn('no', $incomingNos)
                ->where('feature', $featureName === 'finished-goods' ? 'Finished Goods' : 'Material')
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

        DB::transaction(function () use (
            $request,
            $incomingDrafts,
            $detailRows,
            $detailHeaderNames,
            &$detailErrors,
            $file,
        ) {
            $incomingMap = [];
            foreach ($incomingDrafts as $no => $draft) {
                $orderIds = $draft['order_ids'];
                unset($draft['order_ids']);
                $incoming = Incoming::create($draft);
                $incomingMap[$no] = [
                    'id' => $incoming->id,
                    'date' => $incoming->incoming_date,
                    'no' => $incoming->no,
                ];

                foreach ($orderIds as $orderId) {
                    Order::where('id', $orderId)->update(['incoming_id' => $incoming->id]);
                }
            }

            foreach ($detailRows as $i => $row) {
                $rowIndex = $i + 1;
                if ($i <= 1) {
                    continue;
                }
                if ($this->rowEmpty($row)) {
                    continue;
                }

                $incomingNo = trim((string) ($row[1] ?? ''));
                $meta = $incomingMap[$incomingNo] ?? null;
                if (! $meta) {
                    $detailErrors[] = $this->err($rowIndex, $detailHeaderNames[1] ?? 'Incoming No', 'Incoming no tidak ditemukan di sheet header', $incomingNo);
                    continue;
                }

                $item = Item::where('code', trim((string) ($row[2] ?? '')))->first();
                if (! $item) {
                    $detailErrors[] = $this->err($rowIndex, $detailHeaderNames[2] ?? 'Item Code', 'Item tidak ditemukan', (string) ($row[2] ?? ''));
                    continue;
                }

                $detail = IncomingDetail::create([
                    'incoming_id' => $meta['id'],
                    'item_id' => $item->id,
                    'po_quantity' => (float) ($row[3] ?? 0),
                    'quantity' => (float) ($row[4] ?? 0),
                    'remainder_quantity' => (float) ($row[4] ?? 0),
                    'hs_code' => (string) ($row[5] ?? ''),
                    'country' => (string) ($row[6] ?? ''),
                    'amount' => (float) ($row[7] ?? 0),
                    'item_series' => (string) ($row[8] ?? ''),
                ]);

                $this->movementSvc->create([
                    'movement_type' => 'INCOMING_MATERIAL',
                    'movement_date' => $meta['date'],
                    'document_id' => $detail->id,
                    'document_no' => $meta['no'],
                    'item_id' => $detail->item_id,
                    'quantity' => (float) $detail->quantity,
                    'movement_direction' => 'IN',
                    'location_from' => '',
                    'location_to' => 'WAREHOUSE',
                ]);
            }

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_UPLOAD,
                'Incoming',
                'Upload file: '.$file->getClientOriginalName(),
            );
        });

        return ApiResponse::success([
            'errors' => $detailErrors,
            'duplicates' => [],
        ], 'Upload processed');
    }

    private function rowEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function err(int $row, string $column, string $message, string $value): array
    {
        return [
            'row' => $row,
            'column' => $column,
            'message' => $message,
            'value' => $value,
        ];
    }
}
