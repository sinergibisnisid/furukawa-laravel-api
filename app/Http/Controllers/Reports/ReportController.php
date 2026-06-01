<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\ExcelHelper;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 6 laporan kepabeanan aktif + Excel/JSON download.
 *
 * Pola endpoint per laporan:
 *   GET /reports/{name}                   -> paginated list (untuk tabel FE)
 *   GET /reports/{name}/download          -> full list JSON (FE generate PDF)
 *   GET /reports/{name}/download/excel    -> blob xlsx
 *
 * Dispatch nama:
 *   raw-material-intakes        -> ReportService::rawMaterialIntake
 *   raw-material-usages         -> ReportService::rawMaterialUsage
 *   production-result-income    -> ReportService::productionResultIncome
 *   production-expenditure      -> ReportService::productionExpenditure
 *   raw-material-mutation       -> ReportService::rawMaterialMutation
 *   finished-goods-mutation     -> ReportService::finishedGoodsMutation
 *
 * Layout kolom Excel (header + key) dipusatkan di excelLayouts(); ubah
 * di sana bila perlu, tidak perlu menyentuh service.
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $service) {}

    /**
     * GET /reports/{name}
     *
     * FE mengirim page, size, searchQuery, startDate, endDate, isPagination.
     * Diterjemahkan ke shape $opts yang seragam.
     */
    public function paginated(Request $request, string $name): JsonResponse
    {
        $opts = $this->parseOptions($request);
        $result = $this->dispatchService($name, $opts);

        if ($opts['is_pagination']) {
            $perPage = $opts['per_page'];
            $page = $opts['page'];
            $totalPages = $perPage > 0 ? (int) ceil($result['total'] / $perPage) : 1;

            return response()->json([
                'success' => true,
                'message' => 'OK',
                'data' => $result['entries'],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $result['total'],
                    'total_pages' => max(1, $totalPages),
                ],
            ]);
        }

        return ApiResponse::success(['entries' => $result['entries']]);
    }

    /**
     * GET /reports/{name}/download — full data sebagai JSON.
     * FE pakai data ini untuk generate PDF di sisi klien (jspdf).
     */
    public function downloadJson(Request $request, string $name): JsonResponse
    {
        $opts = $this->parseOptions($request);
        $opts['is_pagination'] = false;
        $opts['per_page'] = 100000;
        $result = $this->dispatchService($name, $opts);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => ['entries' => $result['entries']],
        ]);
    }

    /**
     * GET /reports/{name}/download/excel.
     */
    public function downloadExcel(Request $request, string $name): StreamedResponse
    {
        $opts = $this->parseOptions($request);
        $opts['is_pagination'] = false;
        $opts['per_page'] = 100000;
        $result = $this->dispatchService($name, $opts);

        $layout = $this->excelLayouts()[$name] ?? null;
        if ($layout === null) {
            // Fallback: dump entries apa adanya.
            $headers = ! empty($result['entries']) ? array_keys($result['entries'][0]) : [];
            $rows = array_map(fn ($r) => array_values($r), $result['entries']);
            $book = ExcelHelper::buildSimpleXlsx($name, $headers, $rows);

            return ExcelHelper::downloadResponse($book, $name.'.xlsx');
        }

        $book = $this->buildExcelFromLayout($name, $result['entries'], $layout);

        return ExcelHelper::downloadResponse($book, $layout['filename']);
    }

    // ============================================================
    //  Plumbing
    // ============================================================
    private function dispatchService(string $name, array $opts): array
    {
        return match ($name) {
            'raw-material-intakes' => $this->service->rawMaterialIntake($opts),
            'raw-material-usages' => $this->service->rawMaterialUsage($opts),
            'production-result-income' => $this->service->productionResultIncome($opts),
            'production-expenditure' => $this->service->productionExpenditure($opts),
            'raw-material-mutation' => $this->service->rawMaterialMutation($opts),
            'finished-goods-mutation' => $this->service->finishedGoodsMutation($opts),
            default => ['entries' => [], 'total' => 0],
        };
    }

    private function parseOptions(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('size', $request->query('per_page', 25));
        if ($perPage <= 0) {
            $perPage = 25;
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'search' => trim((string) $request->query('searchQuery', $request->query('search', ''))),
            'start_date' => $request->query('startDate', $request->query('start_date')),
            'end_date' => $request->query('endDate', $request->query('end_date')),
            'is_pagination' => filter_var(
                $request->query('isPagination', 'true'),
                FILTER_VALIDATE_BOOLEAN,
            ),
        ];
    }

    /**
     * Layout kolom Excel per laporan. Tiap entry:
     *   filename  — nama file xlsx untuk attachment
     *   headers   — judul kolom (row 1)
     *   keys      — key dari row dict yang dipetakan ke kolom Excel.
     */
    private function excelLayouts(): array
    {
        return [
            'raw-material-intakes' => [
                'filename' => 'Pemasukan_Bahan_Baku.xlsx',
                'headers' => [
                    'Tanggal Catat', 'Jenis Dokumen BC', 'No Dok BC', 'Tanggal Dok BC',
                    'HS Code', 'Item Series', 'No Bukti Penerimaan', 'Tanggal Penerimaan',
                    'Kode BB', 'Nama Barang', 'Satuan', 'Jumlah', 'Mata Uang', 'Harga',
                    'Gudang', 'Penerima Subkontrak', 'Negara Asal',
                ],
                'keys' => [
                    'record_date', 'bc_document_type', 'customs_document_no', 'customs_document_date',
                    'customs_document_hs_code', 'customs_document_item_serial_number',
                    'goods_proof_receipt_no', 'goods_proof_receipt_date',
                    'bb_code', 'goods_name', 'uom_name', 'amount', 'currency_name', 'price',
                    'warehouse', 'subcontract_recipient', 'origin_country',
                ],
            ],
            'raw-material-usages' => [
                'filename' => 'Pemakaian_Bahan_Baku.xlsx',
                'headers' => [
                    'No Bukti Pengeluaran', 'Tanggal Pengeluaran',
                    'Kode Barang', 'Nama Barang', 'Satuan',
                    'Jumlah Pemakaian', 'Jumlah Subkontrak', 'Penerima Subkontrak',
                ],
                'keys' => [
                    'expenditure_proof_no', 'expenditure_proof_date',
                    'goods_code', 'goods_name', 'uom',
                    'amount_used', 'amount_subcontracted', 'subcontract_recipient',
                ],
            ],
            'production-result-income' => [
                'filename' => 'Pemasukan_Hasil_Produksi.xlsx',
                'headers' => [
                    'No Dokumen', 'Tanggal Dokumen', 'Kode Barang', 'Nama Barang',
                    'Satuan', 'Jumlah Produksi', 'Subkontrak', 'Gudang', 'No PIB', 'Tanggal PIB',
                ],
                'keys' => [
                    'document_number', 'document_date', 'goods_code', 'goods_name',
                    'uom_name', 'amount_production', 'subcontract_production',
                    'warehouse', 'pib_no', 'pib_date',
                ],
            ],
            'production-expenditure' => [
                'filename' => 'Pengeluaran_Produksi.xlsx',
                'headers' => [
                    'PEB No', 'PEB Tanggal', 'PIB No', 'PIB Tanggal',
                    'No Pengeluaran', 'Tanggal Pengeluaran',
                    'Pengirim', 'Negara Tujuan',
                    'Kode Barang', 'Nama Barang', 'Satuan',
                    'Jumlah', 'Mata Uang', 'Harga', 'No Lot', 'Tanggal Lot',
                ],
                'keys' => [
                    'peb_no', 'peb_date', 'pib_no', 'pib_date',
                    'expenditure_no', 'expenditure_date',
                    'sender_object', 'destination_country',
                    'goods_code', 'goods_name', 'uom',
                    'amount', 'currency_name', 'price', 'lot_number', 'lot_date',
                ],
            ],
            'raw-material-mutation' => [
                'filename' => 'Mutasi_Bahan_Baku.xlsx',
                'headers' => [
                    'Kode Barang', 'Nama Barang', 'Satuan',
                    'Saldo Awal', 'Pemasukan', 'Pengeluaran', 'Saldo Akhir', 'Gudang',
                ],
                'keys' => [
                    'goods_code', 'goods_name', 'uom',
                    'beginning_balance', 'income', 'expense', 'ending_balance', 'warehouse',
                ],
            ],
            'finished-goods-mutation' => [
                'filename' => 'Mutasi_Barang_Jadi.xlsx',
                'headers' => [
                    'Kode Barang', 'Nama Barang', 'Satuan',
                    'Saldo Awal', 'Pemasukan', 'Pengeluaran', 'Saldo Akhir', 'Gudang',
                ],
                'keys' => [
                    'goods_code', 'goods_name', 'uom',
                    'beginning_balance', 'income', 'expense', 'ending_balance', 'warehouse',
                ],
            ],
        ];
    }

    private function buildExcelFromLayout(string $name, array $entries, array $layout): Spreadsheet
    {
        $headers = $layout['headers'];
        $keys = $layout['keys'];

        $rows = array_map(function ($entry) use ($keys) {
            $row = [];
            foreach ($keys as $k) {
                $row[] = $entry[$k] ?? '';
            }

            return $row;
        }, $entries);

        return ExcelHelper::buildSimpleXlsx($name, $headers, $rows);
    }
}
