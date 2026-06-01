<?php

namespace App\Services;

use App\Exceptions\AppException;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Helper Excel I/O terpusat.
 *
 * Method publik menerima array PHP biasa supaya pemanggil tidak perlu
 * tahu detail phpspreadsheet.
 */
class ExcelHelper
{
    /**
     * Konversi nilai sel Excel ke Carbon date.
     *
     * Terima:
     *   - serial number Excel (numeric, hari sejak 1899-12-30)
     *   - "yyyy-mm-dd"
     *   - "dd/mm/yyyy"
     *   - "yyyy-mm-dd HH:MM:SS"
     */
    public static function excelDateToTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel serial number
        if (is_numeric($value)) {
            $serial = (float) $value;
            try {
                $dt = PhpSpreadsheetDate::excelToDateTimeObject($serial);

                return Carbon::instance($dt);
            } catch (\Throwable $e) {
                // Lanjut ke parsing string
            }
        }

        $value = trim((string) $value);
        $patterns = [
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d',
            'd/m/Y',
            'd-m-Y',
            'm/d/Y',
        ];
        foreach ($patterns as $p) {
            $dt = \DateTime::createFromFormat($p, $value);
            if ($dt !== false) {
                return Carbon::instance($dt)->startOfDay();
            }
        }

        // Last resort: Carbon's lenient parser.
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            throw AppException::badRequest("Invalid date value: {$value}");
        }
    }

    /**
     * 1-indexed column number → spreadsheet letter (1=A, 27=AA).
     */
    public static function columnLetters(int $columnIndex): string
    {
        if ($columnIndex < 1) {
            throw new \InvalidArgumentException('Column index must be >= 1');
        }
        $letters = '';
        while ($columnIndex > 0) {
            $columnIndex--;
            $letters = chr(65 + ($columnIndex % 26)).$letters;
            $columnIndex = intdiv($columnIndex, 26);
        }

        return $letters;
    }

    /**
     * Buka file uploaded atau path filesystem, return Spreadsheet.
     */
    public static function open(string $path): Spreadsheet
    {
        if (! is_file($path)) {
            throw AppException::notFound("Excel file not found: {$path}");
        }
        try {
            return IOFactory::load($path);
        } catch (\Throwable $e) {
            throw AppException::badRequest('Error parsing Excel file: '.$e->getMessage());
        }
    }

    /**
     * Ambil semua row sheet sebagai 2D array (row index 0-based).
     * Tiap row jadi numeric array, kolom A = index 0.
     */
    public static function readSheet(Spreadsheet $book, int $sheetIndex): array
    {
        $sheet = $book->getSheet($sheetIndex);

        return $sheet->toArray(null, true, true, false);
    }

    /**
     * Build xlsx single-sheet di memori.
     *
     * @param  array<int, string>  $headers  judul kolom
     * @param  array<int, array<int, mixed>>  $rows
     */
    public static function buildSimpleXlsx(string $sheetName, array $headers, array $rows): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(substr($sheetName, 0, 31));

        // Header row
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(self::columnLetters($i + 1).'1', $header);
        }

        // Data rows
        $rowNum = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($row as $value) {
                $sheet->setCellValue(self::columnLetters($col).$rowNum, $value);
                $col++;
            }
            $rowNum++;
        }

        return $book;
    }

    /**
     * Stream Spreadsheet sebagai HTTP response (download .xlsx).
     */
    public static function downloadResponse(Spreadsheet $book, string $filename): StreamedResponse
    {
        $writer = new XlsxWriter($book);

        return new StreamedResponse(
            function () use ($writer) {
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ],
        );
    }

    /**
     * Send an existing template file as an attachment download.
     *
     * Templates live under `storage/app/templates/`.
     */
    public static function templateDownload(string $templateName): BinaryFileResponse
    {
        // Sanitize: prevent path traversal.
        $templateName = basename($templateName);
        if ($templateName === '' || $templateName === '.' || $templateName === '..') {
            throw AppException::badRequest('templateName invalid');
        }

        $path = storage_path('app/templates/'.$templateName);
        if (! is_file($path)) {
            throw AppException::notFound("Template tidak ditemukan: {$templateName}");
        }

        return response()->download(
            $path,
            $templateName,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }
}
