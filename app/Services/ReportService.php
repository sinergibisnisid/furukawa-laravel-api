<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\Item;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Layanan 6 laporan kepabeanan (Bea Cukai).
 *
 * Setiap method mengembalikan array dengan bentuk:
 *   { entries: [...], total: int }
 *
 * Key tiap row mengikuti kontrak FE (configs/reportConfig.js):
 *   record_date, customs_document_no, bb_code, goods_name, uom_name, dst.
 *
 * Hitungannya pakai Eloquent query builder, bukan raw SQL.
 */
class ReportService
{
    private const WAREHOUSE_NAME = 'PT Furukawa Automotive Systems Indonesia';

    public function rawMaterialIntake(array $opts): array
    {
        // Pemasukan Bahan Baku: 1 row per IncomingDetail.
        $base = DB::table('incomings_details as id')
            ->join('incomings as i', 'i.id', '=', 'id.incoming_id')
            ->join('items as it', 'it.id', '=', 'id.item_id')
            ->whereNull('it.deleted_at')
            ->select([
                'i.incoming_date as record_date',
                'i.customs_document_number as customs_document_no',
                'i.customs_document_date',
                'id.hs_code as customs_document_hs_code',
                'id.item_series as customs_document_item_serial_number',
                'i.invoice_number as goods_proof_receipt_no',
                'i.incoming_date as goods_proof_receipt_date',
                'it.code as bb_code',
                'it.name as goods_name',
                'it.uom as uom_name',
                'id.quantity as amount',
                'i.currency as currency_name',
                'id.amount as price',
                'id.country as origin_country',
                'id.id as detail_id',
            ]);

        return $this->paginateAndFormat(
            $base,
            $opts,
            dateColumn: 'i.incoming_date',
            searchColumns: ['i.no', 'i.customs_document_number', 'it.code', 'it.name'],
            mapper: fn ($r) => [
                'record_date' => $this->fmt($r->record_date),
                'bc_document_type' => '',
                'customs_document_no' => $r->customs_document_no ?? '',
                'customs_document_date' => $this->fmt($r->customs_document_date),
                'customs_document_hs_code' => $r->customs_document_hs_code ?? '',
                'customs_document_item_serial_number' => $r->customs_document_item_serial_number ?? '',
                'goods_proof_receipt_no' => $r->goods_proof_receipt_no ?? '',
                'goods_proof_receipt_date' => $this->fmt($r->goods_proof_receipt_date),
                'bb_code' => $r->bb_code,
                'goods_name' => $r->goods_name,
                'uom_name' => $r->uom_name,
                'amount' => number_format((float) $r->amount, 2, '.', ''),
                'currency_name' => $r->currency_name ?? '',
                'price' => number_format((float) $r->price, 2, '.', ''),
                'warehouse' => self::WAREHOUSE_NAME,
                'subcontract_recipient' => '',
                'origin_country' => $r->origin_country ?? '',
            ],
        );
    }

    public function rawMaterialUsage(array $opts): array
    {
        // Pemakaian Bahan Baku: row dari outgoings_wip_detail
        // (RM keluar dari warehouse menuju WIP = pemakaian).
        // Tidak filter items.type karena di produksi value-nya tidak konsisten.
        $base = DB::table('outgoings_wip_detail as owd')
            ->join('outgoings_wip as ow', 'ow.id', '=', 'owd.outgoing_wip_id')
            ->join('items as it', 'it.id', '=', 'owd.item_id')
            ->whereNull('it.deleted_at')
            ->select([
                'ow.no as expenditure_proof_no',
                'ow.date as expenditure_proof_date',
                'it.code as goods_code',
                'it.name as goods_name',
                'it.uom as uom',
                'owd.quantity as amount_used',
                'owd.id as detail_id',
            ]);

        return $this->paginateAndFormat(
            $base,
            $opts,
            dateColumn: 'ow.date',
            searchColumns: ['ow.no', 'it.code', 'it.name'],
            mapper: fn ($r) => [
                'expenditure_proof_no' => $r->expenditure_proof_no,
                'expenditure_proof_date' => $this->fmt($r->expenditure_proof_date),
                'goods_code' => $r->goods_code,
                'goods_name' => $r->goods_name,
                'uom' => $r->uom,
                'amount_used' => number_format((float) $r->amount_used, 2, '.', ''),
                'amount_subcontracted' => '0.00',
                'subcontract_recipient' => '',
            ],
        );
    }

    public function productionResultIncome(array $opts): array
    {
        // Pemasukan Hasil Produksi: 1 row per Production header.
        // Item FG diambil dari BOM.finished_good_id.
        $base = DB::table('productions as p')
            ->join('bill_of_materials as bom', 'bom.id', '=', 'p.bill_of_material_id')
            ->join('items as it', 'it.id', '=', 'bom.finished_good_id')
            ->whereNotNull('bom.finished_good_id')
            ->whereNull('it.deleted_at')
            ->select([
                'p.no as document_number',
                'p.date as document_date',
                'it.code as goods_code',
                'it.name as goods_name',
                'it.uom as uom_name',
                'p.total_quantity as amount_production',
                'p.id as production_id',
            ]);

        return $this->paginateAndFormat(
            $base,
            $opts,
            dateColumn: 'p.date',
            searchColumns: ['p.no', 'it.code', 'it.name'],
            mapper: fn ($r) => [
                'document_number' => $r->document_number,
                'document_date' => $this->fmt($r->document_date),
                'goods_code' => $r->goods_code,
                'goods_name' => $r->goods_name,
                'uom_name' => $r->uom_name,
                'amount_production' => (float) $r->amount_production,
                'subcontract_production' => 0.0,
                'warehouse' => self::WAREHOUSE_NAME,
                'pib_no' => '',
                'pib_date' => '',
            ],
        );
    }

    public function productionExpenditure(array $opts): array
    {
        // Pengeluaran Hasil Produksi: outgoings_detail dimana
        // outgoing.feature='Finished Goods'.
        $base = DB::table('outgoings_detail as od')
            ->join('outgoings as o', 'o.id', '=', 'od.outgoing_id')
            ->leftJoin('items as it', 'it.id', '=', 'od.item_id')
            ->leftJoin('companies as c', 'c.id', '=', 'o.company_id')
            ->where('o.feature', '=', 'Finished Goods')
            ->select([
                'o.peb_no',
                'o.peb_date',
                'o.travel_letter_number',
                'o.travel_letter_date',
                'o.outgoing_no',
                'o.outgoing_date',
                'o.currency as currency_name',
                'c.name as sender_object',
                'c.country as destination_country',
                'it.code as goods_code',
                'it.name as goods_name',
                'it.uom',
                'od.quantity as amount',
                'od.amount as price',
                'od.id as detail_id',
            ]);

        return $this->paginateAndFormat(
            $base,
            $opts,
            dateColumn: 'o.outgoing_date',
            searchColumns: ['o.peb_no', 'o.outgoing_no', 'it.code', 'it.name'],
            mapper: fn ($r) => [
                'peb_no' => $r->peb_no ?? '',
                'peb_date' => $this->fmt($r->peb_date),
                'pib_no' => '',
                'pib_date' => '',
                'expenditure_no' => $r->travel_letter_number ?? '',
                'expenditure_date' => $this->fmt($r->travel_letter_date),
                'sender_object' => $r->sender_object ?? '',
                'destination_country' => $r->destination_country ?? '',
                'goods_code' => $r->goods_code ?? '',
                'goods_name' => $r->goods_name ?? '',
                'uom' => $r->uom ?? '',
                'amount' => number_format((float) ($r->amount ?? 0), 5, '.', ''),
                'currency_name' => $r->currency_name ?? '',
                'price' => number_format((float) ($r->price ?? 0), 5, '.', ''),
                'lot_number' => $r->outgoing_no ?? '',
                'lot_date' => $this->fmt($r->outgoing_date),
            ],
        );
    }

    public function rawMaterialMutation(array $opts): array
    {
        // Mutasi Bahan Baku: agregat per item, 1 item bisa keluar 2 row
        // (Furukawa warehouse + WIP) sesuai semantik Go.
        $start = $opts['start_date'] ?? null;
        $end = $opts['end_date'] ?? null;

        // Opening balances
        $openingFurukawa = $this->openingBalanceWarehouse($start);
        $openingWIP = $this->openingBalanceWIP($start);

        // Income / expense dalam range
        $incIn = $this->sumByItem(
            DB::table('incomings_details as id')
                ->join('incomings as i', 'i.id', '=', 'id.incoming_id')
                ->when($start, fn ($q) => $q->whereDate('i.incoming_date', '>=', $start))
                ->when($end, fn ($q) => $q->whereDate('i.incoming_date', '<=', $end))
                ->groupBy('id.item_id')
                ->select(['id.item_id', DB::raw('COALESCE(SUM(id.quantity), 0) as qty')]),
        );

        $wipOut = $this->sumByItem(
            DB::table('outgoings_wip_detail as owd')
                ->join('outgoings_wip as ow', 'ow.id', '=', 'owd.outgoing_wip_id')
                ->when($start, fn ($q) => $q->whereDate('ow.date', '>=', $start))
                ->when($end, fn ($q) => $q->whereDate('ow.date', '<=', $end))
                ->groupBy('owd.item_id')
                ->select(['owd.item_id', DB::raw('COALESCE(SUM(owd.quantity), 0) as qty')]),
        );

        $prodConsume = $this->sumByItem(
            DB::table('productions_detail as pd')
                ->join('productions as p', 'p.id', '=', 'pd.production_id')
                ->where('pd.identifier', '=', 'CONSUME')
                ->when($start, fn ($q) => $q->whereDate('p.date', '>=', $start))
                ->when($end, fn ($q) => $q->whereDate('p.date', '<=', $end))
                ->groupBy('pd.item_id')
                ->select(['pd.item_id', DB::raw('COALESCE(SUM(pd.quantity), 0) as qty')]),
        );

        $itemIds = array_unique(array_merge(
            array_keys($openingFurukawa),
            array_keys($openingWIP),
            array_keys($incIn),
            array_keys($wipOut),
            array_keys($prodConsume),
        ));
        $items = Item::whereIn('id', $itemIds)->withTrashed()->get()->keyBy('id');

        $rows = [];
        foreach ($items as $id => $item) {
            $bbF = $openingFurukawa[$id] ?? 0.0;
            $inc = $incIn[$id] ?? 0.0;
            $expF = $wipOut[$id] ?? 0.0;
            $bbW = $openingWIP[$id] ?? 0.0;
            $incW = $wipOut[$id] ?? 0.0;
            $expW = $prodConsume[$id] ?? 0.0;

            // Furukawa warehouse row
            if ($bbF != 0 || $inc > 0 || $expF > 0) {
                $rows[] = [
                    'goods_code' => $item->code,
                    'goods_name' => $item->name,
                    'uom' => $item->uom,
                    'beginning_balance' => round($bbF, 4),
                    'income' => round($inc, 4),
                    'expense' => round($expF, 4),
                    'ending_balance' => round($bbF + $inc - $expF, 4),
                    'warehouse' => 'Furukawa',
                    'pib_date' => '',
                    'pib_no' => '',
                    'stock_opname_pib_detail' => [],
                ];
            }
            // WIP row
            if ($bbW != 0 || $incW > 0 || $expW > 0) {
                $rows[] = [
                    'goods_code' => $item->code,
                    'goods_name' => $item->name,
                    'uom' => $item->uom,
                    'beginning_balance' => round($bbW, 4),
                    'income' => round($incW, 4),
                    'expense' => round($expW, 4),
                    'ending_balance' => round($bbW + $incW - $expW, 4),
                    'warehouse' => 'WIP',
                    'pib_date' => '',
                    'pib_no' => '',
                    'stock_opname_pib_detail' => [],
                ];
            }
        }

        return $this->paginateRows($rows, $opts);
    }

    public function finishedGoodsMutation(array $opts): array
    {
        // Mutasi Barang Jadi: agregat per FG, 1 row per item.
        $start = $opts['start_date'] ?? null;
        $end = $opts['end_date'] ?? null;

        $openingFG = $this->openingBalanceFG($start);

        $fgIn = $this->sumByItem(
            DB::table('productions as p')
                ->join('bill_of_materials as bom', 'bom.id', '=', 'p.bill_of_material_id')
                ->whereNotNull('bom.finished_good_id')
                ->when($start, fn ($q) => $q->whereDate('p.date', '>=', $start))
                ->when($end, fn ($q) => $q->whereDate('p.date', '<=', $end))
                ->groupBy('bom.finished_good_id')
                ->select([DB::raw('bom.finished_good_id as item_id'), DB::raw('COALESCE(SUM(p.total_quantity), 0) as qty')]),
        );

        $fgOut = $this->sumByItem(
            DB::table('outgoings_detail as od')
                ->join('outgoings as o', 'o.id', '=', 'od.outgoing_id')
                ->whereNotNull('od.item_id')
                ->when($start, fn ($q) => $q->whereDate('o.outgoing_date', '>=', $start))
                ->when($end, fn ($q) => $q->whereDate('o.outgoing_date', '<=', $end))
                ->groupBy('od.item_id')
                ->select(['od.item_id', DB::raw('COALESCE(SUM(od.quantity), 0) as qty')]),
        );

        $itemIds = array_unique(array_merge(array_keys($openingFG), array_keys($fgIn), array_keys($fgOut)));
        $items = Item::whereIn('id', $itemIds)->withTrashed()->get()->keyBy('id');

        $rows = [];
        foreach ($items as $id => $item) {
            $bb = $openingFG[$id] ?? 0.0;
            $inc = $fgIn[$id] ?? 0.0;
            $exp = $fgOut[$id] ?? 0.0;
            if ($bb == 0 && $inc == 0 && $exp == 0) {
                continue;
            }
            $rows[] = [
                'goods_code' => $item->code,
                'goods_name' => $item->name,
                'uom' => $item->uom,
                'beginning_balance' => round($bb, 4),
                'income' => round($inc, 4),
                'expense' => round($exp, 4),
                'ending_balance' => round($bb + $inc - $exp, 4),
                'warehouse' => self::WAREHOUSE_NAME,
                'pib_date' => '',
                'pib_no' => '',
                'stock_opname_pib_detail' => [],
            ];
        }

        return $this->paginateRows($rows, $opts);
    }

    // ============================================================
    //  Helpers
    // ============================================================

    /**
     * Apply pagination + filter pencarian, jalankan query, lewatkan tiap row
     * ke $mapper.
     *
     * @param  callable(\stdClass): array  $mapper
     * @return array{ entries: list<array>, total: int }
     */
    private function paginateAndFormat(
        Builder $base,
        array $opts,
        string $dateColumn,
        array $searchColumns,
        callable $mapper,
    ): array {
        if (! empty($opts['start_date'])) {
            $base->whereDate($dateColumn, '>=', $opts['start_date']);
        }
        if (! empty($opts['end_date'])) {
            $base->whereDate($dateColumn, '<=', $opts['end_date']);
        }
        if (! empty($opts['search'])) {
            $like = '%'.$opts['search'].'%';
            $base->where(function ($q) use ($searchColumns, $like) {
                foreach ($searchColumns as $col) {
                    $q->orWhere($col, 'like', $like);
                }
            });
        }

        $countQuery = clone $base;
        $total = (int) $countQuery->getCountForPagination();

        if (! ($opts['is_pagination'] ?? true)) {
            $rows = $base->orderBy($dateColumn, 'desc')->get();
        } else {
            $page = max(1, (int) ($opts['page'] ?? 1));
            $perPage = (int) ($opts['per_page'] ?? 25);
            if ($perPage <= 0 || $perPage > 1000) {
                $perPage = 25;
            }
            $rows = $base
                ->orderBy($dateColumn, 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();
        }

        $entries = [];
        foreach ($rows as $r) {
            $entries[] = $mapper($r);
        }

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * @return array{ entries: list<array>, total: int }
     */
    private function paginateRows(array $rows, array $opts): array
    {
        $total = count($rows);

        if (! empty($opts['search'])) {
            $needle = strtolower((string) $opts['search']);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                foreach ($r as $v) {
                    if (is_string($v) && str_contains(strtolower($v), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
            $total = count($rows);
        }

        if (! ($opts['is_pagination'] ?? true)) {
            return ['entries' => $rows, 'total' => $total];
        }
        $page = max(1, (int) ($opts['page'] ?? 1));
        $perPage = (int) ($opts['per_page'] ?? 25);
        if ($perPage <= 0 || $perPage > 1000) {
            $perPage = 25;
        }
        $start = ($page - 1) * $perPage;

        return [
            'entries' => array_slice($rows, $start, $perPage),
            'total' => $total,
        ];
    }

    /**
     * @return array<int, float>
     */
    private function sumByItem(Builder $query): array
    {
        $out = [];
        foreach ($query->get() as $r) {
            $out[(int) $r->item_id] = (float) $r->qty;
        }

        return $out;
    }

    /**
     * Saldo awal warehouse Furukawa = sum(incomings.qty) - sum(outgoings_wip.qty)
     * sebelum $beforeDate.
     *
     * @return array<int, float>
     */
    private function openingBalanceWarehouse(?string $beforeDate): array
    {
        $balances = [];
        if ($beforeDate === null || $beforeDate === '') {
            return $balances;
        }

        foreach (
            DB::table('incomings_details as id')
                ->join('incomings as i', 'i.id', '=', 'id.incoming_id')
                ->whereDate('i.incoming_date', '<', $beforeDate)
                ->groupBy('id.item_id')
                ->select(['id.item_id', DB::raw('COALESCE(SUM(id.quantity), 0) as qty')])
                ->get() as $r
        ) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) + (float) $r->qty;
        }

        foreach (
            DB::table('outgoings_wip_detail as owd')
                ->join('outgoings_wip as ow', 'ow.id', '=', 'owd.outgoing_wip_id')
                ->whereDate('ow.date', '<', $beforeDate)
                ->groupBy('owd.item_id')
                ->select(['owd.item_id', DB::raw('COALESCE(SUM(owd.quantity), 0) as qty')])
                ->get() as $r
        ) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) - (float) $r->qty;
        }

        return $balances;
    }

    /**
     * Saldo awal WIP = sum(outgoings_wip) - sum(productions_detail CONSUME).
     *
     * @return array<int, float>
     */
    private function openingBalanceWIP(?string $beforeDate): array
    {
        $balances = [];
        if ($beforeDate === null || $beforeDate === '') {
            return $balances;
        }

        foreach (
            DB::table('outgoings_wip_detail as owd')
                ->join('outgoings_wip as ow', 'ow.id', '=', 'owd.outgoing_wip_id')
                ->whereDate('ow.date', '<', $beforeDate)
                ->groupBy('owd.item_id')
                ->select(['owd.item_id', DB::raw('COALESCE(SUM(owd.quantity), 0) as qty')])
                ->get() as $r
        ) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) + (float) $r->qty;
        }

        foreach (
            DB::table('productions_detail as pd')
                ->join('productions as p', 'p.id', '=', 'pd.production_id')
                ->where('pd.identifier', '=', 'CONSUME')
                ->whereDate('p.date', '<', $beforeDate)
                ->groupBy('pd.item_id')
                ->select(['pd.item_id', DB::raw('COALESCE(SUM(pd.quantity), 0) as qty')])
                ->get() as $r
        ) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) - (float) $r->qty;
        }

        return $balances;
    }

    /**
     * Saldo awal FG = sum(productions.total_quantity by BOM finished_good)
     *               - sum(outgoings_detail.quantity).
     *
     * @return array<int, float>
     */
    private function openingBalanceFG(?string $beforeDate): array
    {
        $balances = [];
        if ($beforeDate === null || $beforeDate === '') {
            return $balances;
        }

        foreach (
            DB::table('productions as p')
                ->join('bill_of_materials as bom', 'bom.id', '=', 'p.bill_of_material_id')
                ->whereNotNull('bom.finished_good_id')
                ->whereDate('p.date', '<', $beforeDate)
                ->groupBy('bom.finished_good_id')
                ->select([DB::raw('bom.finished_good_id as item_id'), DB::raw('COALESCE(SUM(p.total_quantity), 0) as qty')])
                ->get() as $r
        ) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) + (float) $r->qty;
        }

        foreach (
            DB::table('outgoings_detail as od')
                ->join('outgoings as o', 'o.id', '=', 'od.outgoing_id')
                ->whereNotNull('od.item_id')
                ->whereDate('o.outgoing_date', '<', $beforeDate)
                ->groupBy('od.item_id')
                ->select(['od.item_id', DB::raw('COALESCE(SUM(od.quantity), 0) as qty')])
                ->get() as $r
        ) {
            $balances[(int) $r->item_id] = ($balances[(int) $r->item_id] ?? 0.0) - (float) $r->qty;
        }

        return $balances;
    }

    private function fmt(mixed $value): string
    {
        if (! $value) {
            return '';
        }
        try {
            return Carbon::parse((string) $value)->format('d-m-Y');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
