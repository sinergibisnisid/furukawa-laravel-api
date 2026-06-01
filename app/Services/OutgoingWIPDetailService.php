<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\IncomingDetail;
use App\Models\MaterialMovement;
use App\Models\OutgoingWIP;
use App\Models\OutgoingWIPDetail;
use App\Support\Paginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OutgoingWIPDetail service.
 *
 * Flow create():
 *   - Ambil incomings_details untuk itemId yg remainder > 0
 *   - Sort FIFO (customs date, customs no, item series)
 *   - Alokasi qty, tulis 1 OutgoingWIPDetail per chunk allocation
 *   - Tulis OUTGOING_WIP movement (OUT, WAREHOUSE -> WIP) per chunk
 *     dengan parent = INCOMING_MATERIAL movement
 *   - Decrement incomings_details.remainder_quantity
 *
 * Update belum diimplementasikan (di Go juga stub).
 * Delete: refuse kalau material sudah dipakai production, jika tidak
 * rollback remainder_quantity + drop ledger + detail.
 */
class OutgoingWIPDetailService
{
    public function __construct(
        private ActivityLogService $logSvc,
        private MaterialMovementService $movementSvc,
    ) {}

    public function findAllPagination(Request $request, ?int $outgoingWIPId = null)
    {
        $id = $outgoingWIPId
            ?: ($request->query('outgoing_wip_id') ? (int) $request->query('outgoing_wip_id') : null);

        $query = OutgoingWIPDetail::with(['item', 'incomingDetail.incoming']);
        if ($id) {
            $query->where('outgoing_wip_id', $id);
        }

        return Paginator::apply($query, $request);
    }

    /**
     * @param  array{
     *   outgoing_wip_id: int,
     *   item_id: int,
     *   quantity: float,
     *   amount?: float|null,
     * }  $data
     */
    public function create(Request $request, array $data): array
    {
        return DB::transaction(function () use ($data, $request) {
            $qtyNeeded = (float) $data['quantity'];
            if ($qtyNeeded <= 0) {
                throw AppException::badRequest('Quantity must be greater than 0');
            }

            $header = OutgoingWIP::findOrFail($data['outgoing_wip_id']);
            $itemId = (int) $data['item_id'];
            $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;

            $created = $this->createDetailWithAllocation(
                $header,
                $itemId,
                $qtyNeeded,
                $amount,
            );

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_CREATE,
                'Outgoing WIP Detail',
                "Created {$qtyNeeded} units for OutgoingWIP #{$header->id} item #{$itemId}",
            );

            return $created;
        });
    }

    public function delete(Request $request, int $id): void
    {
        DB::transaction(function () use ($id, $request) {
            $detail = OutgoingWIPDetail::lockForUpdate()->findOrFail($id);

            // Tolak kalau material dari detail ini sudah dipakai production.
            $usedInProduction = DB::table('material_movements as mm_consume')
                ->join('material_movements as mm_wip', 'mm_consume.parent_movement_id', '=', 'mm_wip.id')
                ->where('mm_wip.document_id', $id)
                ->where('mm_wip.movement_type', MaterialMovement::TYPE_OUTGOING_WIP)
                ->where('mm_consume.movement_type', MaterialMovement::TYPE_PRODUCTION_CONSUME)
                ->exists();
            if ($usedInProduction) {
                throw AppException::conflict(
                    'Tidak dapat menghapus Outgoing WIP Detail. Material sudah digunakan di production.',
                );
            }

            // Restore incoming detail remainder per OUTGOING_WIP movement.
            $wipMovements = MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_WIP)
                ->where('document_id', $id)
                ->get();
            foreach ($wipMovements as $mv) {
                if (! $mv->parent_movement_id) {
                    continue;
                }
                $incomingMovement = MaterialMovement::find($mv->parent_movement_id);
                if (! $incomingMovement) {
                    continue;
                }
                DB::table('incomings_details')
                    ->where('id', $incomingMovement->document_id)
                    ->update([
                        'remainder_quantity' => DB::raw(
                            'remainder_quantity + '.((string) $mv->quantity),
                        ),
                    ]);
            }

            MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_WIP)
                ->where('document_id', $id)
                ->delete();

            $detail->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_DELETE,
                'Outgoing WIP Detail',
                "Deleted OutgoingWIPDetail #{$id}",
            );
        });
    }

    /**
     * Alokasi FIFO ke incomings_details, tulis 1 detail + 1 OUTGOING_WIP
     * movement per chunk allocation.
     *
     * @return list<OutgoingWIPDetail>
     */
    private function createDetailWithAllocation(
        OutgoingWIP $header,
        int $itemId,
        float $qtyNeeded,
        float $amount,
    ): array {
        $candidates = IncomingDetail::query()
            ->select('incomings_details.*')
            ->join('incomings', 'incomings.id', '=', 'incomings_details.incoming_id')
            ->where('incomings_details.item_id', $itemId)
            ->where('incomings_details.remainder_quantity', '>', 0)
            ->orderBy('incomings.customs_document_date', 'asc')
            ->orderBy('incomings.customs_document_number', 'asc')
            ->orderBy('incomings_details.item_series', 'asc')
            ->orderBy('incomings_details.id', 'asc')
            ->lockForUpdate()
            ->get();

        $available = (float) $candidates->sum('remainder_quantity');
        if ($available + 1e-9 < $qtyNeeded) {
            throw AppException::badRequest(
                'Insufficient stock. Available: '.round($available, 4).
                ', Needed: '.round($qtyNeeded, 4),
            );
        }

        $created = [];
        $remaining = $qtyNeeded;

        foreach ($candidates as $incomingDetail) {
            if ($remaining <= 1e-9) {
                break;
            }

            $take = min($remaining, (float) $incomingDetail->remainder_quantity);

            // Lookup INCOMING_MATERIAL movement untuk parent + root traceability.
            $incomingMovement = $this->movementSvc->findRootIncomingForDetail($incomingDetail->id);

            // Decrement incoming detail remainder.
            DB::table('incomings_details')
                ->where('id', $incomingDetail->id)
                ->update([
                    'remainder_quantity' => DB::raw(
                        'remainder_quantity - '.((string) $take),
                    ),
                ]);

            // Create one OutgoingWIPDetail per allocation chunk (matches Go).
            $detail = OutgoingWIPDetail::create([
                'outgoing_wip_id' => $header->id,
                'incoming_detail_id' => $incomingDetail->id,
                'item_id' => $itemId,
                'quantity' => $take,
                'amount' => $amount,
                'remainder_quantity' => $take,
            ]);

            // Write OUTGOING_WIP movement.
            $this->movementSvc->create([
                'movement_type' => MaterialMovement::TYPE_OUTGOING_WIP,
                'movement_date' => $header->date,
                'document_id' => $detail->id,
                'document_no' => $header->no,
                'item_id' => $itemId,
                'quantity' => $take,
                'movement_direction' => MaterialMovement::DIRECTION_OUT,
                'location_from' => MaterialMovement::LOC_WAREHOUSE,
                'location_to' => MaterialMovement::LOC_WIP,
                'parent_movement_id' => $incomingMovement?->id,
                'root_incoming_material_movement_id' => $incomingMovement?->id,
            ]);

            $created[] = $detail;
            $remaining -= $take;
        }

        if ($remaining > 1e-9) {
            throw AppException::internal('OutgoingWIP allocation incomplete.');
        }

        return $created;
    }
}
