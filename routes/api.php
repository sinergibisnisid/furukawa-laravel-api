<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Audit\ActivityLogController;
use App\Http\Controllers\Auth\PermissionController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Auth\UserGroupController;
use App\Http\Controllers\Master\BCLKTFinishedGoodController;
use App\Http\Controllers\Master\BCLKTRawMaterialController;
use App\Http\Controllers\Master\BillOfMaterialController;
use App\Http\Controllers\Master\BillOfMaterialDetailController;
use App\Http\Controllers\Master\CompanyController;
use App\Http\Controllers\Master\CompanyTypeController;
use App\Http\Controllers\Master\CurrencyController;
use App\Http\Controllers\Master\ItemController;
use App\Http\Controllers\Master\OfficeCodeController;
use App\Http\Controllers\Reports\DeprecatedReportController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\TemplateDownloadController;
use App\Http\Controllers\Transaction\IncomingController;
use App\Http\Controllers\Transaction\IncomingDetailController;
use App\Http\Controllers\Transaction\OrderController;
use App\Http\Controllers\Transaction\OrderDetailController;
use App\Http\Controllers\Transaction\OutgoingController;
use App\Http\Controllers\Transaction\OutgoingDetailController;
use App\Http\Controllers\Transaction\OutgoingWIPController;
use App\Http\Controllers\Transaction\OutgoingWIPDetailController;
use App\Http\Controllers\Transaction\ProductionController;
use App\Http\Controllers\Transaction\ProductionDetailController;
use App\Http\Controllers\Transaction\StockOpnameController;
use App\Http\Controllers\Transaction\StockOpnameDetailController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Otomatis di-mount Laravel di prefix `/api`. js-furukawa-client
| memakai {API_BASE_URL} untuk memanggil endpoint:
|   - dev    : NEXT_PUBLIC_API_BASE_URL=http://localhost:8080  (tanpa /api)
|   - prod   : NEXT_PUBLIC_API_BASE_URL=https://kite.furukawa.id/api
| Maka route di sini harus jawab di `/...` dan `/api/...` sekaligus.
| Lihat bootstrap/app.php — file ini di-mount dua kali.
*/

// ============================================================================
// Public auth
// ============================================================================
Route::post('/authentication/login', [AuthController::class, 'login']);

// ============================================================================
// Protected
// ============================================================================
Route::middleware('auth:sanctum')->group(function () {
    // -- auth / me --
    Route::post('/authentication/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/me/change-password', [AuthController::class, 'changePassword']);

    // -- users --
    Route::prefix('users')->group(function () {
        Route::get('/pagination', [UserController::class, 'findAllPagination']);
        Route::post('', [UserController::class, 'create']);
        Route::put('', [UserController::class, 'update']);
        Route::delete('/{id}', [UserController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('id');
    });

    // -- user groups --
    Route::prefix('user-groups')->group(function () {
        Route::get('', [UserGroupController::class, 'findAll']);
        Route::get('/pagination', [UserGroupController::class, 'findAllPagination']);
        Route::post('', [UserGroupController::class, 'create']);
        Route::put('', [UserGroupController::class, 'update']);
        Route::delete('/{id}', [UserGroupController::class, 'destroy'])->whereNumber('id');
    });

    // -- permissions (read only) --
    Route::prefix('permissions')->group(function () {
        Route::get('', [PermissionController::class, 'findAll']);
        Route::get('/pagination', [PermissionController::class, 'findAllPagination']);
    });

    // -- activity logs --
    Route::prefix('activity-logs')->group(function () {
        Route::get('', [ActivityLogController::class, 'findAll']);
        Route::get('/pagination', [ActivityLogController::class, 'findAllPagination']);
    });

    // -- master: currencies --
    Route::prefix('currencies')->group(function () {
        Route::get('', [CurrencyController::class, 'findAll']);
        Route::get('/pagination', [CurrencyController::class, 'findAllPagination']);
        Route::post('', [CurrencyController::class, 'create']);
        Route::put('', [CurrencyController::class, 'update']);
        Route::delete('/{id}', [CurrencyController::class, 'destroy'])->whereNumber('id');
    });

    // -- master: items --
    Route::prefix('items')->group(function () {
        Route::get('', [ItemController::class, 'findAll']);
        Route::get('/pagination', [ItemController::class, 'findAllPagination']);
        Route::post('', [ItemController::class, 'create']);
        Route::put('', [ItemController::class, 'update']);
        Route::delete('/{id}', [ItemController::class, 'destroy'])->whereNumber('id');
    });

    // -- master: office codes --
    Route::prefix('office-codes')->group(function () {
        Route::get('', [OfficeCodeController::class, 'findAll']);
        Route::get('/pagination', [OfficeCodeController::class, 'findAllPagination']);
        Route::post('', [OfficeCodeController::class, 'create']);
        Route::put('', [OfficeCodeController::class, 'update']);
        Route::delete('/{id}', [OfficeCodeController::class, 'destroy'])->whereNumber('id');
    });

    // -- master: company types --
    Route::prefix('company-types')->group(function () {
        Route::get('', [CompanyTypeController::class, 'findAll']);
        Route::get('/pagination', [CompanyTypeController::class, 'findAllPagination']);
        Route::post('', [CompanyTypeController::class, 'create']);
        Route::put('', [CompanyTypeController::class, 'update']);
        Route::delete('/{id}', [CompanyTypeController::class, 'destroy'])->whereNumber('id');
    });

    // -- master: companies --
    Route::prefix('companies')->group(function () {
        Route::get('', [CompanyController::class, 'findAll']);
        Route::get('/pagination', [CompanyController::class, 'findAllPagination']);
        Route::get('/{id}', [CompanyController::class, 'show'])->whereNumber('id');
        Route::post('', [CompanyController::class, 'create']);
        Route::put('/{id}', [CompanyController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [CompanyController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================================
    // Transactional: Incomings + IncomingDetails
    // ============================================================
    Route::prefix('incomings')->group(function () {
        Route::get('/dependency', [IncomingController::class, 'dependency']);
        Route::get('/pagination', [IncomingController::class, 'findAllPagination']);
        Route::get('/pagination/{featureName}', [IncomingController::class, 'findAllPagination']);
        Route::get('', [IncomingController::class, 'findAll']);
        Route::post('', [IncomingController::class, 'store']);
        Route::put('', [IncomingController::class, 'update']);                       // legacy: id in body
        Route::put('/{id}', [IncomingController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [IncomingController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('incomings-detail')->group(function () {
        Route::get('/pagination', [IncomingDetailController::class, 'findAllPagination']);
        Route::get('/pagination/{incomingId}', [IncomingDetailController::class, 'findAllPagination'])
            ->whereNumber('incomingId');
        Route::post('', [IncomingDetailController::class, 'store']);
        Route::put('', [IncomingDetailController::class, 'update']);
        Route::put('/{id}', [IncomingDetailController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [IncomingDetailController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================================
    // Orders + OrderDetail
    // ============================================================
    Route::prefix('orders')->group(function () {
        Route::get('', [OrderController::class, 'findAll']);
        Route::get('/pagination', [OrderController::class, 'findAllPagination']);
        Route::post('', [OrderController::class, 'create']);
        Route::put('', [OrderController::class, 'update']);
        Route::put('/{id}', [OrderController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [OrderController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('orders-detail')->group(function () {
        Route::get('/pagination', [OrderDetailController::class, 'findAllPagination']);
        Route::post('', [OrderDetailController::class, 'create']);
        Route::put('', [OrderDetailController::class, 'update']);
        Route::put('/{id}', [OrderDetailController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [OrderDetailController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================================
    // Bill of Materials + Detail
    // ============================================================
    Route::prefix('bill-of-materials')->group(function () {
        Route::get('', [BillOfMaterialController::class, 'findAll']);
        Route::get('/pagination', [BillOfMaterialController::class, 'findAllPagination']);
        Route::get('/{id}', [BillOfMaterialController::class, 'show'])->whereNumber('id');
        Route::post('', [BillOfMaterialController::class, 'create']);
        Route::put('', [BillOfMaterialController::class, 'update']);
        Route::put('/{id}', [BillOfMaterialController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [BillOfMaterialController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('bill-of-materials-detail')->group(function () {
        Route::get('', [BillOfMaterialDetailController::class, 'findAll']);
        Route::get('/pagination', [BillOfMaterialDetailController::class, 'findAllPagination']);
        Route::get('/pagination/{bomId}', [BillOfMaterialDetailController::class, 'findAllPagination'])
            ->whereNumber('bomId');
        Route::post('', [BillOfMaterialDetailController::class, 'create']);
        Route::put('', [BillOfMaterialDetailController::class, 'update']);
        Route::put('/{id}', [BillOfMaterialDetailController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [BillOfMaterialDetailController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================================
    // Productions (FIFO Engine) + ProductionDetail (read-only trace)
    // ============================================================
    Route::prefix('productions')->group(function () {
        Route::get('', [ProductionController::class, 'findAll']);
        Route::get('/pagination', [ProductionController::class, 'findAllPagination']);
        Route::post('', [ProductionController::class, 'store']);
        Route::put('', [ProductionController::class, 'update']);
        Route::put('/{id}', [ProductionController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ProductionController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('productions-detail')->group(function () {
        Route::get('/filter', [ProductionDetailController::class, 'findFiltered']);
        Route::get('/pagination', [ProductionDetailController::class, 'findAllPagination']);
        Route::get('/pagination/{productionId}', [ProductionDetailController::class, 'findAllPagination'])
            ->whereNumber('productionId');
    });

    // ============================================================
    // Outgoings (FIFO FG) + OutgoingDetail
    // ============================================================
    Route::prefix('outgoings')->group(function () {
        Route::get('/dependency', [OutgoingController::class, 'dependency']);
        Route::get('/pagination', [OutgoingController::class, 'findAllPagination']);
        Route::get('', [OutgoingController::class, 'findAll']);
        Route::post('', [OutgoingController::class, 'store']);
        Route::put('', [OutgoingController::class, 'update']);
        Route::put('/{id}', [OutgoingController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [OutgoingController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('outgoings-detail')->group(function () {
        Route::get('/pagination', [OutgoingDetailController::class, 'findAllPagination']);
        Route::get('/pagination/{outgoingId}', [OutgoingDetailController::class, 'findAllPagination'])
            ->whereNumber('outgoingId');
        Route::post('', [OutgoingDetailController::class, 'store']);
        Route::put('', [OutgoingDetailController::class, 'update']);
        Route::put('/{id}', [OutgoingDetailController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [OutgoingDetailController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================================
    // Outgoings WIP + Detail (FIFO from incomings)
    // ============================================================
    Route::prefix('outgoings-wip')->group(function () {
        Route::get('', [OutgoingWIPController::class, 'findAll']);
        Route::get('/pagination', [OutgoingWIPController::class, 'findAllPagination']);
        Route::post('', [OutgoingWIPController::class, 'store']);
        Route::put('', [OutgoingWIPController::class, 'update']);
        Route::put('/{id}', [OutgoingWIPController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [OutgoingWIPController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('outgoings-wip-detail')->group(function () {
        Route::get('/pagination', [OutgoingWIPDetailController::class, 'findAllPagination']);
        Route::get('/pagination/{outgoingWIPId}', [OutgoingWIPDetailController::class, 'findAllPagination'])
            ->whereNumber('outgoingWIPId');
        Route::post('', [OutgoingWIPDetailController::class, 'store']);
        Route::delete('/{id}', [OutgoingWIPDetailController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================================
    // BCLKT (Customs documents — simple CRUD)
    // ============================================================
    Route::prefix('bclkt-finished-goods')->group(function () {
        Route::get('', [BCLKTFinishedGoodController::class, 'findAll']);
        Route::get('/pagination', [BCLKTFinishedGoodController::class, 'findAllPagination']);
        Route::post('', [BCLKTFinishedGoodController::class, 'create']);
        Route::put('', [BCLKTFinishedGoodController::class, 'update']);
        Route::put('/{id}', [BCLKTFinishedGoodController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [BCLKTFinishedGoodController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('bclkt-raw-materials')->group(function () {
        Route::get('', [BCLKTRawMaterialController::class, 'findAll']);
        Route::get('/pagination', [BCLKTRawMaterialController::class, 'findAllPagination']);
        Route::post('', [BCLKTRawMaterialController::class, 'create']);
        Route::put('', [BCLKTRawMaterialController::class, 'update']);
        Route::put('/{id}', [BCLKTRawMaterialController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [BCLKTRawMaterialController::class, 'destroy'])->whereNumber('id');
    });

    // ============================================================
    // Stock Opname header CRUD + StockOpnameDetail calculation/download
    // ============================================================
    Route::prefix('stocks-opname')->group(function () {
        Route::get('', [StockOpnameController::class, 'findAll']);
        Route::get('/pagination', [StockOpnameController::class, 'findAllPagination']);
        Route::post('', [StockOpnameController::class, 'create']);
        Route::put('', [StockOpnameController::class, 'update']);
        Route::put('/{id}', [StockOpnameController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [StockOpnameController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('stocks-opname-detail')->group(function () {
        Route::get('', [StockOpnameDetailController::class, 'calculate']);
        Route::post('/download', [StockOpnameDetailController::class, 'download']);
    });

    // ============================================================
    // Reports — template download (Sesi 6) + 6 active reports + placeholders
    // ============================================================
    Route::get('/reports/download-template', [TemplateDownloadController::class, 'download']);

    // 6 active reports (Sesi 8). Order: longest path first so
    // /download/excel and /download don't collide with /{name}.
    $activeReports = [
        'raw-material-intakes',
        'raw-material-usages',
        'production-result-income',
        'production-expenditure',
        'raw-material-mutation',
        'finished-goods-mutation',
    ];
    foreach ($activeReports as $name) {
        Route::get('/reports/'.$name.'/download/excel', [ReportController::class, 'downloadExcel'])
            ->defaults('name', $name);
        Route::get('/reports/'.$name.'/download', [ReportController::class, 'downloadJson'])
            ->defaults('name', $name);
        Route::get('/reports/'.$name, [ReportController::class, 'paginated'])
            ->defaults('name', $name);
    }

    // Placeholders for FE pages that never had a backend.
    $deprecatedReports = [
        'goods-usage-subcontract',
        'machine-equipment-expenditure',
        'machine-equipment-income',
        'machine-equipment-mutation',
        'material-expenditure',
    ];
    foreach ($deprecatedReports as $name) {
        Route::get('/reports/'.$name.'/download/excel', [DeprecatedReportController::class, 'downloadExcel'])
            ->defaults('name', $name);
        Route::get('/reports/'.$name.'/download', [DeprecatedReportController::class, 'downloadJson'])
            ->defaults('name', $name);
        Route::get('/reports/'.$name, [DeprecatedReportController::class, 'paginated'])
            ->defaults('name', $name);
    }
});
