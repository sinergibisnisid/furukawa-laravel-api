<?php

namespace App\Http\Controllers;

use App\Models\Incoming;
use App\Models\Item;
use App\Models\Order;
use App\Models\Outgoing;
use App\Models\Production;
use App\Models\StockOpname;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /public/homes/dashboard
     *
     * Public endpoint — no auth required.
     * Returns all stats needed by the Next.js home/dashboard page.
     */
    public function index(): JsonResponse
    {
        $currentYear = now()->year;

        // ── Stats card totals ───────────────────────────────────────
        $totalIncoming = Incoming::count();
        $totalOutgoing = Outgoing::count();

        // ── Monthly stats (current year) ────────────────────────────
        $incomingPerMonth = Incoming::select(
            DB::raw('MONTH(incoming_date) as Month'),
            DB::raw('COUNT(*) as Value')
        )
            ->whereYear('incoming_date', $currentYear)
            ->groupBy(DB::raw('MONTH(incoming_date)'))
            ->get();

        $outgoingPerMonth = Outgoing::select(
            DB::raw('MONTH(outgoing_date) as Month'),
            DB::raw('COUNT(*) as Value')
        )
            ->whereYear('outgoing_date', $currentYear)
            ->groupBy(DB::raw('MONTH(outgoing_date)'))
            ->get();

        // ── Sales & Purchase Order stats per month (array of 12) ────
        $salesOrderPerMonth = $this->orderStatsPerMonth('Sales', $currentYear);
        $purchaseOrderPerMonth = $this->orderStatsPerMonth('Purchase', $currentYear);

        // ── Item by type ────────────────────────────────────────────
        $itemByType = Item::select(
            DB::raw('type as Name'),
            DB::raw('COUNT(*) as Value')
        )
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->groupBy('type')
            ->get();

        // ── Latest records (top 10) ─────────────────────────────────
        $incomingResponses = Incoming::with('company')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row, $i) => array_merge($row->toArray(), ['no' => $i + 1]));

        $outgoingResponses = Outgoing::with('company')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($row, $i) {
                $arr = $row->toArray();
                $arr['no'] = $i + 1;
                $arr['date'] = $row->outgoing_date;
                // FE column accessors expect currency_response.name & company_response.name
                $arr['currency_response'] = ['name' => $row->currency];
                $arr['company_response'] = ['name' => $row->company?->name];
                return $arr;
            });

        $productionResponses = Production::with('billOfMaterial')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row, $i) => array_merge($row->toArray(), ['no' => $i + 1]));

        $stockOpnameResponses = StockOpname::orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row, $i) => array_merge($row->toArray(), ['no' => $i + 1]));

        // ── Compose response (PascalCase keys to match Go-era FE) ───
        return ApiResponse::success([
            'HomeStatsCard' => [
                'TotalIncoming' => $totalIncoming,
                'TotalOutgoing' => $totalOutgoing,
                'SalesOrderStatsPerMonth' => $salesOrderPerMonth,
                'PurchaseOrderStatsPerMonth' => $purchaseOrderPerMonth,
            ],
            'IncomingStatsPerMonth' => $incomingPerMonth,
            'OutgoingStatsPerMonth' => $outgoingPerMonth,
            'SalesOrderStatsPerMonth' => $salesOrderPerMonth,
            'PurchaseOrderStatsPerMonth' => $purchaseOrderPerMonth,
            'ItemByType' => $itemByType,
            'IncomingResponses' => $incomingResponses,
            'OutgoingResponses' => $outgoingResponses,
            'ProductionResponses' => $productionResponses,
            'StockOpnameResponses' => $stockOpnameResponses,
        ]);
    }

    /**
     * Build an array of 12 integers (one per month) for a given order feature.
     */
    private function orderStatsPerMonth(string $feature, int $year): array
    {
        $rows = Order::select(
            DB::raw('MONTH(date) as m'),
            DB::raw('COUNT(*) as c')
        )
            ->where('feature', $feature)
            ->whereYear('date', $year)
            ->groupBy(DB::raw('MONTH(date)'))
            ->pluck('c', 'm');

        $result = [];
        for ($i = 1; $i <= 12; $i++) {
            $result[] = (int) ($rows[$i] ?? 0);
        }

        return $result;
    }
}
