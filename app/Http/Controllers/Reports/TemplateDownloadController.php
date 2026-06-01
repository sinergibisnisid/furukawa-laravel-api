<?php

namespace App\Http\Controllers\Reports;

use App\Exceptions\AppException;
use App\Http\Controllers\Controller;
use App\Services\ExcelHelper;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /reports/download-template?templateName=format-upload-data-incoming.xlsx
 *
 * FE memanggil endpoint ini untuk download template Excel
 * (lihat js-furukawa-client/src/hooks/useIncoming.js, useItem.js, useBOM.js).
 *
 * File template tersimpan di storage/app/templates/, di-copy 1:1 dari
 * go-furukawa-api/public/template/ supaya format Bea Cukai tetap.
 */
class TemplateDownloadController extends Controller
{
    public function download(Request $request): BinaryFileResponse
    {
        $name = (string) $request->query('templateName', '');
        if ($name === '') {
            throw AppException::badRequest('templateName is required');
        }

        return ExcelHelper::templateDownload($name);
    }
}
