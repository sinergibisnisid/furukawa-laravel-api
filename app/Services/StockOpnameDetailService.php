<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\Item;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Hitungan StockOpnameDetail.
 *
 * 3 mode keyed pada query param `feature`:
 *   - "Materials"      saldo gudang Furukawa
 *       Awal    = sum(incomings_details) - sum(outgoings_wip_detail) sebelum start
 *       Masuk   = sum(incomings_details) dalam range, lewat incomings.incoming_date
 *       Keluar  = sum(outgoings_wip_detail) dalam range, lewat outgoings_wip.date
 *
 *   - "WIP"            saldo work-in-progress
 *       Awal    = sum(outgoings_wip_detail) - sum(productions_detail) sebelum start
 *       Masuk   = sum(outgoings_wip_detail) dalam range
 *       Keluar  = sum(productions_detail) dalam range
 *
 *   - "Finished Goods" saldo gudang FG
 *       Awal    = sum(productions.total_quantity) - sum(outgoings_detail) sebelum start
 *       Masuk   = sum(productions.total_quantity) dalam range
 *       Keluar  = sum(outgoings_detail) dalam range
 *
 * Item FG diambil dari bill_of_materials.finished_good_id.
 * Adjustment dari tracing_stocks_opname digabung apa adanya.
 */
class StockOpnameDetailService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function calculate(string $feature, ?string $startDate, ?string $endDate): array
    {
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        return match ($feature) {
            'Materials' => $this->calcMaterials($start, $end),
            'WIP' => $this->calcWIP($start, $end),
            'Finished Goods' => $this->calcFinishedGoods($start, $end),
            default => throw AppException::badRequest(
                'Feature must be one of: Materials, WIP, Finished Goods',
            ),
        };
    }

    /**
     * Build xlsx single-sheet dari hasil hitungan.
     */
    public function buildDownload(string $feature, ?string $startDate, ?string $endDate): Spreadsheet
    {
        $rows = $this->calculate($feature, $startDate, $endDate);

        $headers = [
            'No',
            'Kode Barang',
            'Nama Barang',
            'Saldo Awal',
            'Pemasukan',
            'Pengeluaran',
            'Adjust In',
            'Adjust Out',
            'Stock Opname',
            'Saldo Akhir',
            'Selisih',
        ];

        $data = [];
        foreach ($rows as $i => $r) {
            $data[] = [
                $i + 1,
                $r['item_code'],
                $r['item_name'],
                $r['beginning_balance'],
                $r['income'],
                $r['expense'],
                $r['adjust_in'],
                $r['adjust_out'],
                $r['stock_opname'],
                $r['ending_balance'],
                $r['difference'],
            ];
        }

        return ExcelHelper::buildSimpleXlsx($feature, $headers, $data);
    }

    // === Materials (gudang Furukawa) ===
    private function calcMaterials(?Carbon $start, ?Carbon $end): array
    {
        $opening = $this->aggregateOpening(
            inFn: function () use ($start) {
                return DB::table('incomings_details')
                    ->join('incomings', 'incomings.id', '=', 'incomings_details.incoming_id')
                    ->when($start, fn ($q) => $q->whereDate('incomings.incoming_date', '<', $start->toDateString()))
                    ->groupBy('incomings_details.item_id')
                    ->select(['incomings_details.item_id as item_id', DB::raw('COALESCE(SUM(incomings_details.quantity), 0) as qty')]);
            },
            outFn: function () use ($start) {
                return DB::table('outgoings_wip_detail')
                    ->join('outgoings_wip', 'outgoings_wip.id', '=', 'outgoings_wip_detail.outgoing_wip_id')
                    ->when($start, fn ($q) => $q->whereDate('outgoings_wip.date', '<', $start->toDateString()))
                    ->groupBy('outgoings_wip_detail.item_id')
                    ->select(['outgoings_wip_detail.item_id as item_id', DB::raw('COALESCE(SUM(outgoings_wip_detail.quantity), 0) as qty')]);
            },
        );

        $incomeRange = $this->fetchSum(
            DB::table('incomings_details')
                ->join('incomings', 'incomings.id', '=', 'incomings_details.incoming_id')
                ->when($start, fn ($q) => $q->whereDate('incomings.incoming_date', '>=', $start->toDateString()))
                ->when($end, fn ($q) => $q->whereDate('incomings.incoming_date', '<=', $end->toDateString()))
                ->groupBy('incomings_details.item_id')
                ->select(['incomings_details.item_id as item_id', DB::raw('COALESCE(SUM(incomings_details.quantity), 0) as qty')]),
        );

        $expenseRange = $this->fetchSum(
            DB::table('outgoings_wip_detail')
                ->join('outgoings_wip', 'outgoings_wip.id', '=', 'outgoings_wip_detail.outgoing_wip_id')
                ->when($start, fn ($q) => $q->whereDate('outgoings_wip.date', '>=', $start->toDateString()))
                ->when($end, fn ($q) => $q->whereDate('outgoings_wip.date', '<=', $end->toDateString()))
                ->groupBy('outgoings_wip_detail.item_id')
                ->select(['outgoings_wip_detail.item_id as item_id', DB::raw('COALESCE(SUM(outgoings_wip_detail.quantity), 0) as qty')]),
        );

        return $this->buildResult('Materials', $opening, $incomeRange, $expenseRange, $start, $end);
    }

    // === WIP ===
    private function calcWIP(?Carbon $start, ?Carbon $end): array
    {
        $opening = $this->aggregateOpening(
            inFn: function () use ($start) {
                return DB::table('outgoings_wip_detail')
                    ->join('outgoings_wip', 'outgoings_wip.id', '=', 'outgoings_wip_detail.outgoing_wip_id')
                    ->when($start, fn ($q) => $q->whereDate('outgoings_wip.date', '<', $start->toDateString()))
                    ->groupBy('outgoings_wip_detail.item_id')
                    ->select(['outgoings_wip_detail.item_id as item_id', DB::raw('COALESCE(SUM(outgoings_wip_detail.quantity), 0) as qty')]);
            },
            outFn: function () use ($start) {
                return DB::table('productions_detail')
                    ->join('productions', 'productions.id', '=', 'productions_detail.production_id')
                    ->when($start, fn ($q) => $q->whereDate('productions.date', '<', $start->toDateString()))
                    ->where('productions_detail.identifier', '=', 'CONSUME')
                    ->groupBy('productions_detail.item_id')
                    ->select(['productions_detail.item_id as item_id', DB::raw('COALESCE(SUM(productions_detail.quantity), 0) as qty')]);
            },
        );

        $incomeRange = $this->fetchSum(
            DB::table('outgoings_wip_detail')
                ->join('outgoings_wip', 'outgoings_wip.id', '=', 'outgoings_wip_detail.outgoing_wip_id')
                ->when($start, fn ($q) => $q->whereDate('outgoings_wip.date', '>=', $start->toDateString()))
                ->when($end, fn ($q) => $q->whereDate('outgoings_wip.date', '<=', $end->toDateString()))
                ->groupBy('outgoings_wip_detail.item_id')
                ->select(['outgoings_wip_detail.item_id as item_id', DB::raw('COALESCE(SUM(outgoings_wip_detail.quantity), 0) as qty')]),
        );

        $expenseRange = $this->fetchSum(
            DB::table('productions_detail')
                ->join('productions', 'productions.id', '=', 'productions_detail.production_id')
                ->when($start, fn ($q) => $q->whereDate('productions.date', '>=', $start->toDateString()))
                ->when($end, fn ($q) => $q->whereDate('productions.date', '<=', $end->toDateString()))
                ->where('productions_detail.identifier', '=', 'CONSUME')
                ->groupBy('productions_detail.item_id')
                ->select(['productions_detail.item_id as item_id', DB::raw('COALESCE(SUM(productions_detail.quantity), 0) as qty')]),
        );

        return $this->buildResult('WIP', $opening, $incomeRange, $expenseRange, $start, $end);
    }

    // === Finished Goods ===
    private function calcFinishedGoods(?Carbon $start, ?Carbon $end): array
    {
        // Schema doesn't carry productions.item_id; FG item is resolved
        // via BOM.finished_good_id. We use SUM(total_quantity) as income
        // (each production produces N units of the BOM's finished_good).
        $opening = $this->aggregateOpening(
            inFn: function () use ($start) {
                return DB::table('productions')
                    ->join('bill_of_materials', 'bill_of_materials.id', '=', 'productions.bill_of_material_id')
                    ->whereNotNull('bill_of_materials.finished_good_id')
                    ->when($start, fn ($q) => $q->whereDate('productions.date', '<', $start->toDateString()))
                    ->groupBy('bill_of_materials.finished_good_id')
                    ->select(['bill_of_materials.finished_good_id as item_id', DB::raw('COALESCE(SUM(productions.total_quantity), 0) as qty')]);
            },
            outFn: function () use ($start) {
                return DB::table('outgoings_detail')
                    ->join('outgoings', 'outgoings.id', '=', 'outgoings_detail.outgoing_id')
                    ->whereNotNull('outgoings_detail.item_id')
                    ->when($start, fn ($q) => $q->whereDate('outgoings.outgoing_date', '<', $start->toDateString()))
                    ->groupBy('outgoings_detail.item_id')
                    ->select(['outgoings_detail.item_id as item_id', DB::raw('COALESCE(SUM(outgoings_detail.quantity), 0) as qty')]);
            },
        );

        $incomeRange = $this->fetchSum(
            DB::table('productions')
                ->join('bill_of_materials', 'bill_of_materials.id', '=', 'productions.bill_of_material_id')
                ->whereNotNull('bill_of_materials.finished_good_id')
                ->when($start, fn ($q) => $q->whereDate('productions.date', '>=', $start->toDateString()))
                ->when($end, fn ($q) => $q->whereDate('productions.date', '<=', $end->toDateString()))
                ->groupBy('bill_of_materials.finished_good_id')
                ->select(['bill_of_materials.finished_good_id as item_id', DB::raw('COALESCE(SUM(productions.total_quantity), 0) as qty')]),
        );

        $expenseRange = $this->fetchSum(
            DB::table('outgoings_detail')
                ->join('outgoings', 'outgoings.id', '=', 'outgoings_detail.outgoing_id')
                ->whereNotNull('outgoings_detail.item_id')
                ->when($start, fn ($q) => $q->whereDate('outgoings.outgoing_date', '>=', $start->toDateString()))
                ->when($end, fn ($q) => $q->whereDate('outgoings.outgoing_date', '<=', $end->toDateString()))
                ->groupBy('outgoings_detail.item_id')
                ->select(['outgoings_detail.item_id as item_id', DB::raw('COALESCE(SUM(outgoings_detail.quantity), 0) as qty')]),
        );

        return $this->buildResult('Finished Goods', $opening, $incomeRange, $expenseRange, $start, $end);
    }

    // === Helpers ===

    /**
     * @param  callable(): Builder  $inFn   query yang menambah saldo lokasi sebelum $start
     * @param  callable(): Builder  $outFn  query yang mengurangi saldo lokasi sebelum $start
     * @return array<int, float>  itemId => saldo awal
     */
    private function aggregateOpening(callable $inFn, callable $outFn): array
    {
        $balances = [];

        foreach ($inFn()->get() as $r) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) + (float) $r->qty;
        }
        foreach ($outFn()->get() as $r) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) - (float) $r->qty;
        }

        return $balances;
    }

    /**
     * Jalankan query agregat per item dan reduce jadi map itemId => qty.
     *
     * @return array<int, float>
     */
    private function fetchSum(Builder $query): array
    {
        $out = [];
        foreach ($query->get() as $r) {
            $out[(int) $r->item_id] = (float) $r->qty;
        }

        return $out;
    }

    /**
     * Gabungkan map opening / income / expense / adjustment dan emit 1 row
     * per item yang punya nilai non-zero.
     *
     * @param  array<int, float>  $opening
     * @param  array<int, float>  $income
     * @param  array<int, float>  $expense
     * @return list<array<string, mixed>>
     */
    private function buildResult(
        string $feature,
        array $opening,
        array $income,
        array $expense,
        ?Carbon $start,
        ?Carbon $end,
    ): array {
        // Ambil adjustment + stock_opname dari tracing_stocks_opname.
        $tracing = DB::table('tracing_stocks_opname')
            ->where('feature', $feature)
            ->when($start, fn ($q) => $q->whereDate('date', '>=', $start->toDateString()))
            ->when($end, fn ($q) => $q->whereDate('date', '<=', $end->toDateString()))
            ->groupBy('item_id')
            ->select([
                'item_id',
                DB::raw('SUM(adjust_in) as adjust_in'),
                DB::raw('SUM(adjust_out) as adjust_out'),
                DB::raw('SUM(stock_opname) as stock_opname'),
            ])
            ->get();

        $adjustIn = [];
        $adjustOut = [];
        $stockOpname = [];
        foreach ($tracing as $t) {
            $adjustIn[(int) $t->item_id] = (float) $t->adjust_in;
            $adjustOut[(int) $t->item_id] = (float) $t->adjust_out;
            $stockOpname[(int) $t->item_id] = (float) $t->stock_opname;
        }

        $itemIds = array_unique(array_merge(
            array_keys($opening),
            array_keys($income),
            array_keys($expense),
            array_keys($adjustIn),
            array_keys($adjustOut),
            array_keys($stockOpname),
        ));

        if (empty($itemIds)) {
            return [];
        }

        $items = Item::whereIn('id', $itemIds)
            ->withTrashed()
            ->orderBy('code')
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($items as $id => $item) {
            $bb = $opening[$id] ?? 0.0;
            $inc = $income[$id] ?? 0.0;
            $exp = $expense[$id] ?? 0.0;
            $ai = $adjustIn[$id] ?? 0.0;
            $ao = $adjustOut[$id] ?? 0.0;
            $so = $stockOpname[$id] ?? 0.0;

            // Skip kalau semua nilai nol.
            if (abs($bb) < 1e-9 && abs($inc) < 1e-9 && abs($exp) < 1e-9
                && abs($ai) < 1e-9 && abs($ao) < 1e-9 && abs($so) < 1e-9) {
                continue;
            }

            $ending = $bb + $inc + $ai - $exp - $ao;
            $diff = abs($so) > 1e-9 ? ($so - $ending) : 0.0;

            $rows[] = [
                'item_id' => (int) $id,
                'item_code' => $item->code,
                'item_name' => $item->name,
                'beginning_balance' => round($bb, 4),
                'income' => round($inc, 4),
                'expense' => round($exp, 4),
                'adjust_in' => round($ai, 4),
                'adjust_out' => round($ao, 4),
                'stock_opname' => round($so, 4),
                'ending_balance' => round($ending, 4),
                'difference' => round($diff, 4),
            ];
        }

        return $rows;
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            throw AppException::badRequest("Invalid date: {$value}");
        }
    }
}
