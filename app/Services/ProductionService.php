<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\BillOfMaterial;
use App\Models\IncomingDetail;
use App\Models\MaterialMovement;
use App\Models\Production;
use App\Models\ProductionDetail;
use App\Models\ProductionDetailLink;
use App\Support\Paginator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Production FIFO Engine.
 *
 * Flow create():
 *   1. Load BOM with details.
 *   2. BOM expansion: per BOM detail, qty_needed = bom_detail.quantity * total_quantity.
 *   3. Cek stock per item: SUM(incomings_details.remainder_quantity) where item_id=X
 *      AND incomings.incoming_date <= production.date. Tolak jika kurang.
 *   4. Insert Production header (remainder_quantity = total_quantity).
 *   5. FIFO consume per item urut:
 *        customs_document_date ASC, customs_document_number ASC, item_series ASC, id ASC
 *      Per allocation:
 *        - tulis ProductionDetail(identifier=CONSUME, quantity=qty_alokasi)
 *        - tulis ProductionDetailLink(production_detail_id, incoming_detail_id, quantity)
 *        - decrement incomings_details.remainder_quantity
 *        - tulis MaterialMovement(PRODUCTION_CONSUME, OUT, WIP→PRODUCTION,
 *          parent=INCOMING_MATERIAL movement, root=parent.id)
 *   6. Jika BOM punya finished_good_id:
 *        - tulis ProductionDetail(identifier=PRODUCE, item=FG, quantity=total_quantity)
 *        - tulis MaterialMovement(PRODUCTION_PRODUCE, IN, PRODUCTION→FG,
 *          parent=null, root=salah satu root pertama)
 *
 * Flow delete():
 *   1. Cek tidak ada outgoings_detail yang reference production_id ini.
 *   2. Untuk tiap link: kembalikan incomings_details.remainder_quantity += link.quantity.
 *   3. Hapus ProductionDetailLink, MaterialMovement (CONSUME + PRODUCE), ProductionDetail.
 *   4. Hapus Production header.
 *
 * Flow update(): header-only (no, date, description, total_quantity tidak boleh diubah
 * jika ada child detail). Mengubah total_quantity setelah create akan membuat
 * stock allocation jadi stale, jadi kita tolak.
 */
class ProductionService
{
    public function __construct(
        private ActivityLogService $logSvc,
        private MaterialMovementService $movementSvc,
    ) {}

    public function findAll(?string $feature = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Production::with([
            'billOfMaterial.finishedGoodItem',
            'billOfMaterial.details.item',
            'details.item',
        ])->orderBy('id', 'desc');

        if ($feature) {
            $query->where('feature', $feature);
        }

        return $query->get();
    }

    public function findAllPagination(Request $request)
    {
        $query = Production::with([
            'billOfMaterial.finishedGoodItem',
            'details.item',
        ]);

        if ($feature = $request->query('feature')) {
            $query->where('feature', $feature);
        }

        return Paginator::apply($query, $request, ['no', 'description']);
    }

    /**
     * @param  array{
     *   no: string,
     *   date: string,
     *   bill_of_material_id: int,
     *   total_quantity: float,
     *   description?: string|null,
     *   feature?: string|null,
     * }  $data
     */
    public function create(Request $request, array $data): Production
    {
        return DB::transaction(function () use ($data, $request) {
            $totalQty = (float) $data['total_quantity'];
            if ($totalQty <= 0) {
                throw AppException::badRequest('total_quantity harus lebih dari 0');
            }

            $productionDate = Carbon::parse($data['date']);

            // -------------------------------------------------- Step 1: BOM
            $bom = BillOfMaterial::with(['details.item', 'finishedGoodItem'])
                ->findOrFail($data['bill_of_material_id']);

            if ($bom->details->isEmpty()) {
                throw AppException::badRequest('BOM tidak punya detail material.');
            }

            // -------------------------------------------------- Step 2: requirements
            // Aggregate per item (BOM bisa berisi item duplikat).
            $requirements = []; // item_id => ['needed' => float, 'item' => Item]
            foreach ($bom->details as $bd) {
                $itemId = (int) $bd->item_id;
                if (! isset($requirements[$itemId])) {
                    $requirements[$itemId] = [
                        'needed' => 0.0,
                        'item' => $bd->item,
                    ];
                }
                $requirements[$itemId]['needed'] += (float) $bd->quantity * $totalQty;
            }

            // -------------------------------------------------- Step 3: check stock
            // Lock kandidat row supaya production paralel tidak double-spend.
            $candidates = IncomingDetail::query()
                ->select('incomings_details.*')
                ->join('incomings', 'incomings.id', '=', 'incomings_details.incoming_id')
                ->whereIn('incomings_details.item_id', array_keys($requirements))
                ->where('incomings_details.remainder_quantity', '>', 0)
                ->whereDate('incomings.incoming_date', '<=', $productionDate->toDateString())
                ->orderBy('incomings.customs_document_date', 'asc')
                ->orderBy('incomings.customs_document_number', 'asc')
                ->orderBy('incomings_details.item_series', 'asc')
                ->orderBy('incomings_details.id', 'asc')
                ->lockForUpdate()
                ->get();

            // Group kandidat per item.
            $byItem = $candidates->groupBy('item_id');

            // Validasi ketersediaan stok per kebutuhan.
            $deficits = [];
            foreach ($requirements as $itemId => $req) {
                $available = (float) ($byItem->get($itemId)?->sum('remainder_quantity') ?? 0);
                if ($available + 1e-9 < $req['needed']) {
                    $deficits[] = [
                        'item_id' => $itemId,
                        'item_code' => $req['item']?->code,
                        'item_name' => $req['item']?->name,
                        'required' => round($req['needed'], 4),
                        'available' => round($available, 4),
                        'deficit' => round($req['needed'] - $available, 4),
                    ];
                }
            }
            if ($deficits) {
                throw AppException::badRequest(
                    'Stock material tidak mencukupi untuk produksi.',
                    $deficits,
                );
            }

            // -------------------------------------------------- Step 4: header
            $production = Production::create([
                'no' => $data['no'],
                'date' => $productionDate,
                'bill_of_material_id' => $bom->id,
                'feature' => $data['feature'] ?? 'Finished Goods',
                'description' => $data['description'] ?? null,
                'total_quantity' => $totalQty,
                'remainder_quantity' => $totalQty,
            ]);

            // -------------------------------------------------- Step 5: FIFO consume
            $rootMovementIds = [];

            foreach ($requirements as $itemId => $req) {
                $needed = $req['needed'];
                $itemCandidates = $byItem->get($itemId) ?? collect();

                // Agregat semua qty consume per item ke 1 ProductionDetail.
                // Pemecahan per-incoming disimpan di productions_detail_links.
                $consumeDetail = ProductionDetail::create([
                    'production_id' => $production->id,
                    'item_id' => $itemId,
                    'po_quantity' => $needed,
                    'quantity' => $needed,
                    'remainder_quantity' => $needed,
                    'identifier' => ProductionDetail::IDENT_CONSUME,
                ]);

                foreach ($itemCandidates as $detail) {
                    if ($needed <= 1e-9) {
                        break;
                    }

                    $available = (float) $detail->remainder_quantity;
                    $take = min($needed, $available);

                    // Lookup INCOMING_MATERIAL movement yang dibuat saat incoming-detail.
                    $incomingMovement = $this->movementSvc->findRootIncomingForDetail($detail->id);

                    // Decrement incoming_details.remainder_quantity atomically.
                    DB::table('incomings_details')
                        ->where('id', $detail->id)
                        ->update([
                            'remainder_quantity' => DB::raw(
                                'remainder_quantity - '.((string) $take),
                            ),
                        ]);

                    // Persist allocation link.
                    ProductionDetailLink::create([
                        'production_detail_id' => $consumeDetail->id,
                        'incoming_detail_id' => $detail->id,
                        'quantity' => $take,
                    ]);

                    // PRODUCTION_CONSUME ledger entry.
                    $consumeMovement = $this->movementSvc->create([
                        'movement_type' => MaterialMovement::TYPE_PRODUCTION_CONSUME,
                        'movement_date' => $productionDate,
                        'document_id' => $production->id,
                        'document_no' => $production->no,
                        'item_id' => $itemId,
                        'quantity' => $take,
                        'movement_direction' => MaterialMovement::DIRECTION_OUT,
                        'location_from' => MaterialMovement::LOC_WIP,
                        'location_to' => MaterialMovement::LOC_PRODUCTION,
                        'parent_movement_id' => $incomingMovement?->id,
                        'root_incoming_material_movement_id' => $incomingMovement?->id,
                    ]);

                    if ($incomingMovement) {
                        $rootMovementIds[$incomingMovement->id] = true;
                    }

                    $needed -= $take;
                }

                if ($needed > 1e-9) {
                    // Tidak seharusnya terjadi (sudah lewat deficit pre-check),
                    // tapi kalau iya, gagalkan transaksi.
                    throw AppException::internal(
                        'FIFO allocation incomplete (concurrent allocation?).',
                    );
                }
            }

            // -------------------------------------------------- Step 6: PRODUCE
            if ($bom->finished_good_id) {
                $produceDetail = ProductionDetail::create([
                    'production_id' => $production->id,
                    'item_id' => $bom->finished_good_id,
                    'po_quantity' => $totalQty,
                    'quantity' => $totalQty,
                    'remainder_quantity' => $totalQty,
                    'identifier' => ProductionDetail::IDENT_PRODUCE,
                ]);

                $firstRoot = array_key_first($rootMovementIds);

                $this->movementSvc->create([
                    'movement_type' => MaterialMovement::TYPE_PRODUCTION_PRODUCE,
                    'movement_date' => $productionDate,
                    'document_id' => $production->id,
                    'document_no' => $production->no,
                    'item_id' => $bom->finished_good_id,
                    'quantity' => $totalQty,
                    'movement_direction' => MaterialMovement::DIRECTION_IN,
                    'location_from' => MaterialMovement::LOC_PRODUCTION,
                    'location_to' => MaterialMovement::LOC_FG,
                    'parent_movement_id' => null,
                    'root_incoming_material_movement_id' => $firstRoot ?: null,
                ]);
            }

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_CREATE,
                'Production',
                "Created Production #{$production->id} ({$production->no})",
            );

            return $production->load(['billOfMaterial.finishedGoodItem', 'details.item']);
        });
    }

    /**
     * Update header-only. Tolak perubahan yang bisa bikin FIFO state stale
     * (BOM swap, total_quantity change). Untuk perubahan tsb minta user
     * delete + create ulang.
     */
    public function update(Request $request, array $data): Production
    {
        return DB::transaction(function () use ($data, $request) {
            $production = Production::with('details')
                ->lockForUpdate()
                ->findOrFail($data['id']);

            $forbiddenChanges = [];
            if (array_key_exists('bill_of_material_id', $data)
                && (int) $data['bill_of_material_id'] !== (int) $production->bill_of_material_id) {
                $forbiddenChanges[] = 'bill_of_material_id';
            }
            if (array_key_exists('total_quantity', $data)
                && abs(((float) $data['total_quantity']) - (float) $production->total_quantity) > 1e-9) {
                $forbiddenChanges[] = 'total_quantity';
            }

            if ($forbiddenChanges) {
                throw AppException::badRequest(
                    'Field tidak boleh diubah setelah production dibuat: '
                    .implode(', ', $forbiddenChanges)
                    .'. Hapus dan buat ulang production untuk perubahan ini.',
                );
            }

            $production->fill(array_intersect_key($data, array_flip([
                'no', 'description', 'feature',
            ])));

            if (array_key_exists('date', $data)) {
                $production->date = Carbon::parse($data['date']);
                // Update movement_date supaya ledger konsisten.
                MaterialMovement::where('document_id', $production->id)
                    ->whereIn('movement_type', [
                        MaterialMovement::TYPE_PRODUCTION_CONSUME,
                        MaterialMovement::TYPE_PRODUCTION_PRODUCE,
                    ])
                    ->update(['movement_date' => $production->date]);
            }

            $production->save();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_UPDATE,
                'Production',
                "Updated Production #{$production->id}",
            );

            return $production->load(['billOfMaterial.finishedGoodItem', 'details.item']);
        });
    }

    public function delete(Request $request, int $id): void
    {
        DB::transaction(function () use ($id, $request) {
            $production = Production::with('details')
                ->lockForUpdate()
                ->findOrFail($id);

            // Tolak kalau FG sudah diambil outgoing.
            $hasOutgoing = DB::table('outgoings_detail')
                ->where('production_id', $id)
                ->exists();
            if ($hasOutgoing) {
                throw AppException::badRequest(
                    'Production sudah dipakai outgoing/FG. Hapus outgoing dulu.',
                );
            }

            $detailIds = $production->details->pluck('id')->all();

            // Restore incomings_details.remainder_quantity untuk tiap link CONSUME.
            $links = ProductionDetailLink::whereIn('production_detail_id', $detailIds)->get();
            foreach ($links as $link) {
                if ($link->incoming_detail_id) {
                    DB::table('incomings_details')
                        ->where('id', $link->incoming_detail_id)
                        ->update([
                            'remainder_quantity' => DB::raw(
                                'remainder_quantity + '.((string) $link->quantity),
                            ),
                        ]);
                }
            }

            // Drop link row.
            ProductionDetailLink::whereIn('production_detail_id', $detailIds)->delete();

            // Drop ledger (CONSUME + PRODUCE) by document_id.
            MaterialMovement::where('document_id', $id)
                ->whereIn('movement_type', [
                    MaterialMovement::TYPE_PRODUCTION_CONSUME,
                    MaterialMovement::TYPE_PRODUCTION_PRODUCE,
                ])
                ->delete();

            // Drop production_detail row, baru hapus header.
            ProductionDetail::whereIn('id', $detailIds)->delete();
            $production->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_DELETE,
                'Production',
                "Deleted Production #{$id}",
            );
        });
    }
}
