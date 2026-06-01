<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\BillOfMaterial;
use App\Models\MaterialMovement;
use App\Models\Outgoing;
use App\Models\OutgoingDetail;
use App\Models\Production;
use App\Support\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OutgoingDetail FIFO FG service.
 *
 * Flow create():
 *   1. Load Outgoing header for date cutoff.
 *   2. Determine the FG item id:
 *        - if `production_id` provided: use that production's BOM finished_good_id.
 *        - else if `item_id` provided: treat that as the FG item, FIFO across
 *          all productions whose BOM points at the same finished_good_id.
 *   3. Load candidate productions: feature='Finished Goods', remainder>0,
 *      date <= outgoing.date, ordered date ASC, id ASC. Lock for update.
 *   4. Validate availability sum >= requested qty.
 *   5. Insert OutgoingDetail row (production_id = first allocation's production
 *      for backwards compat with FE; the actual split is in the ledger).
 *   6. For each FIFO allocation:
 *        - decrement productions.remainder_quantity
 *        - lookup PRODUCTION_PRODUCE movement to capture parent + root
 *        - write OUTGOING_FG movement (OUT, FG -> "")
 *
 * Flow delete():
 *   - Find OUTGOING_FG movements by document_id = detail.id, restore
 *     productions.remainder_quantity for the parent's document_id, drop
 *     movements, drop detail.
 */
class OutgoingDetailService
{
    public function __construct(
        private ActivityLogService $logSvc,
        private MaterialMovementService $movementSvc,
    ) {}

    public function findAllPagination(Request $request, ?int $outgoingId = null)
    {
        $id = $outgoingId
            ?: ($request->query('outgoing_id') ? (int) $request->query('outgoing_id') : null);

        $query = OutgoingDetail::with([
            'item',
            'production.billOfMaterial.finishedGoodItem',
        ]);
        if ($id) {
            $query->where('outgoing_id', $id);
        }

        return Paginator::apply($query, $request);
    }

    /**
     * @param  array{
     *   outgoing_id: int,
     *   production_id?: int|null,
     *   item_id?: int|null,
     *   quantity: float,
     *   amount?: float|null,
     *   item_series?: string|null,
     * }  $data
     */
    public function create(Request $request, array $data): OutgoingDetail
    {
        return DB::transaction(function () use ($data, $request) {
            $qtyNeeded = (float) $data['quantity'];
            if ($qtyNeeded <= 0) {
                throw AppException::badRequest('Quantity harus lebih dari 0.');
            }

            $outgoing = Outgoing::findOrFail($data['outgoing_id']);

            $fgItemId = $data['item_id'] ?? null;
            $singleProductionId = $data['production_id'] ?? null;

            // Insert detail dengan placeholder; allocateFifo() yang isi
            // production_id dari alokasi pertama kalau perlu.
            $detail = OutgoingDetail::create([
                'outgoing_id' => $outgoing->id,
                'item_id' => $fgItemId, // may be filled from BOM by allocateFifo()
                'quantity' => $qtyNeeded,
                'amount' => isset($data['amount']) ? (float) $data['amount'] : 0,
                'remainder_quantity' => $qtyNeeded,
                'production_id' => $singleProductionId,
                'item_series' => $data['item_series'] ?? null,
            ]);

            $this->allocateFifo(
                outgoing: $outgoing,
                detail: $detail,
                fgItemId: $fgItemId,
                singleProductionId: $singleProductionId,
                quantity: $qtyNeeded,
            );

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_CREATE,
                'OutgoingDetail',
                "Created OutgoingDetail #{$detail->id} (FIFO FG)",
            );

            return $detail->load(['item', 'production.billOfMaterial.finishedGoodItem']);
        });
    }

    /**
     * Alokasi $quantity unit FG ke productions secara FIFO, tulis OUTGOING_FG
     * movement, dan decrement productions.remainder_quantity.
     *
     * Kalau $fgItemId null, di-resolve dari BOM kandidat pertama lalu
     * di-set ke $detail->item_id.
     *
     * Kalau $detail->production_id kosong setelah alokasi, di-set ke
     * production pertama yang dialokasikan (untuk display FE).
     */
    private function allocateFifo(
        Outgoing $outgoing,
        OutgoingDetail $detail,
        ?int $fgItemId,
        ?int $singleProductionId,
        float $quantity,
    ): void {
        $candidates = Production::query()
            ->select('productions.*')
            ->with(['billOfMaterial:id,finished_good_id'])
            ->join('bill_of_materials', 'bill_of_materials.id', '=', 'productions.bill_of_material_id')
            ->where('productions.feature', 'Finished Goods')
            ->where('productions.remainder_quantity', '>', 0)
            ->whereDate('productions.date', '<=', $outgoing->outgoing_date?->toDateString() ?? now()->toDateString())
            ->orderBy('productions.date', 'asc')
            ->orderBy('productions.id', 'asc')
            ->lockForUpdate();

        if ($singleProductionId) {
            $candidates->where('productions.id', $singleProductionId);
        } else {
            if (! $fgItemId) {
                throw AppException::badRequest('item_id atau production_id wajib diisi.');
            }
            $candidates->where('bill_of_materials.finished_good_id', $fgItemId);
        }

        $rows = $candidates->get();

        if ($rows->isEmpty()) {
            throw AppException::badRequest('Tidak ada production FG yang tersedia untuk dialokasikan.');
        }

        if (! $fgItemId) {
            $fgItemId = (int) $rows->first()->billOfMaterial?->finished_good_id;
            if (! $fgItemId) {
                throw AppException::internal('Production tidak punya BOM finished_good_id.');
            }
            // Backfill detail.item_id kalau caller hanya kirim production_id.
            if (! $detail->item_id) {
                $detail->item_id = $fgItemId;
                $detail->save();
            }
        }

        $available = (float) $rows->sum('remainder_quantity');
        if ($available + 1e-9 < $quantity) {
            throw AppException::badRequest(
                'Stock FG tidak mencukupi. Tersedia: '.round($available, 4).
                ', dibutuhkan: '.round($quantity, 4),
            );
        }

        $remaining = $quantity;
        $firstAllocatedProductionId = null;
        foreach ($rows as $production) {
            if ($remaining <= 1e-9) {
                break;
            }
            $take = min($remaining, (float) $production->remainder_quantity);

            DB::table('productions')
                ->where('id', $production->id)
                ->update([
                    'remainder_quantity' => DB::raw(
                        'remainder_quantity - '.((string) $take),
                    ),
                ]);

            $produceMovement = MaterialMovement::where('movement_type', MaterialMovement::TYPE_PRODUCTION_PRODUCE)
                ->where('document_id', $production->id)
                ->where('item_id', $fgItemId)
                ->first();

            $this->movementSvc->create([
                'movement_type' => MaterialMovement::TYPE_OUTGOING_FG,
                'movement_date' => $outgoing->outgoing_date,
                'document_id' => $detail->id,
                'document_no' => (string) ($outgoing->outgoing_no ?? ''),
                'item_id' => $fgItemId,
                'quantity' => $take,
                'movement_direction' => MaterialMovement::DIRECTION_OUT,
                'location_from' => MaterialMovement::LOC_FG,
                'location_to' => '',
                'parent_movement_id' => $produceMovement?->id,
                'root_incoming_material_movement_id' => $produceMovement?->root_incoming_material_movement_id,
            ]);

            if ($firstAllocatedProductionId === null) {
                $firstAllocatedProductionId = (int) $production->id;
            }

            $remaining -= $take;
        }

        if ($remaining > 1e-9) {
            throw AppException::internal('FIFO FG allocation incomplete.');
        }

        if (! $detail->production_id && $firstAllocatedProductionId) {
            $detail->production_id = $firstAllocatedProductionId;
            $detail->save();
        }
    }

    /**
     * Update OutgoingDetail.
     *
     * Saat `quantity` berubah: rollback alokasi FIFO FG yang ada
     * (restore productions.remainder + drop OUTGOING_FG movements) lalu
     * alokasi ulang dengan qty baru. Field lain (amount, item_series)
     * di-update apa adanya.
     */
    public function update(Request $request, array $data): OutgoingDetail
    {
        return DB::transaction(function () use ($data, $request) {
            $detail = OutgoingDetail::lockForUpdate()->findOrFail($data['id']);

            $newQuantity = array_key_exists('quantity', $data)
                ? (float) $data['quantity']
                : (float) $detail->quantity;

            $quantityChanged = abs($newQuantity - (float) $detail->quantity) > 1e-9;

            if ($quantityChanged) {
                if ($newQuantity <= 0) {
                    throw AppException::badRequest('Quantity harus lebih dari 0.');
                }

                // Rollback existing OUTGOING_FG allocations.
                $this->rollbackAllocation($detail);

                // Re-allocate cross-production using FG item id. We don't
                // re-use the original production_id because the new quantity
                // may need stock from other productions as well.
                $outgoing = Outgoing::findOrFail($detail->outgoing_id);
                $fgItemId = (int) $detail->item_id;

                // Reset detail.production_id; allocateFifo() will set it
                // from the first allocation.
                $detail->production_id = null;
                $detail->save();

                $this->allocateFifo(
                    outgoing: $outgoing,
                    detail: $detail,
                    fgItemId: $fgItemId,
                    singleProductionId: null,
                    quantity: $newQuantity,
                );

                $detail->quantity = $newQuantity;
                $detail->remainder_quantity = $newQuantity;
            }

            // Update field biasa yang tidak mempengaruhi alokasi.
            $detail->fill(array_intersect_key($data, array_flip([
                'amount', 'item_series',
            ])));

            $detail->save();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_UPDATE,
                'OutgoingDetail',
                "Updated OutgoingDetail #{$detail->id}",
            );

            return $detail->load(['item', 'production.billOfMaterial.finishedGoodItem']);
        });
    }

    /**
     * Reverse alokasi OUTGOING_FG yang ada untuk detail row, restore
     * productions.remainder_quantity dan drop ledger row. TIDAK menghapus
     * detail row itu sendiri.
     */
    private function rollbackAllocation(OutgoingDetail $detail): void
    {
        $movements = MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_FG)
            ->where('document_id', $detail->id)
            ->get();

        foreach ($movements as $mv) {
            if (! $mv->parent_movement_id) {
                continue;
            }
            $produce = MaterialMovement::find($mv->parent_movement_id);
            if (! $produce) {
                continue;
            }
            DB::table('productions')
                ->where('id', $produce->document_id)
                ->update([
                    'remainder_quantity' => DB::raw(
                        'remainder_quantity + '.((string) $mv->quantity),
                    ),
                ]);
        }

        MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_FG)
            ->where('document_id', $detail->id)
            ->delete();

        DB::table('outgoings_detail_incoming')
            ->where('outgoing_detail_id', $detail->id)
            ->delete();
    }

    public function delete(Request $request, int $id): void
    {
        DB::transaction(function () use ($id, $request) {
            $detail = OutgoingDetail::lockForUpdate()->findOrFail($id);

            // Cari semua OUTGOING_FG movement detail ini.
            // Tiap row kasih tau berapa qty yang harus dikembalikan ke production mana.
            $movements = MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_FG)
                ->where('document_id', $detail->id)
                ->get();

            foreach ($movements as $mv) {
                if (! $mv->parent_movement_id) {
                    continue;
                }
                // Parent = PRODUCTION_PRODUCE, document_id-nya = production id.
                $produce = MaterialMovement::find($mv->parent_movement_id);
                if (! $produce) {
                    continue;
                }
                DB::table('productions')
                    ->where('id', $produce->document_id)
                    ->update([
                        'remainder_quantity' => DB::raw(
                            'remainder_quantity + '.((string) $mv->quantity),
                        ),
                    ]);
            }

            // Drop OUTGOING_FG ledger row.
            MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_FG)
                ->where('document_id', $detail->id)
                ->delete();

            // Materials-style detail might have legacy outgoings_detail_incoming
            // pivot rows pointing at it; clear those defensively.
            DB::table('outgoings_detail_incoming')
                ->where('outgoing_detail_id', $detail->id)
                ->delete();

            $detail->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_DELETE,
                'OutgoingDetail',
                "Deleted OutgoingDetail #{$id}",
            );
        });
    }
}
