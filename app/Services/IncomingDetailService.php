<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\Incoming;
use App\Models\IncomingDetail;
use App\Models\MaterialMovement;
use App\Support\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * IncomingDetail service.
 *
 * Tiap create/update/delete dibungkus 1 transaksi dan menulis/mutasi
 * INCOMING_MATERIAL movement supaya ledger konsisten.
 */
class IncomingDetailService
{
    public function __construct(
        private ActivityLogService $logSvc,
        private MaterialMovementService $movementSvc,
    ) {}

    public function findAllPagination(Request $request, ?int $incomingId = null)
    {
        $query = IncomingDetail::with(['item', 'incoming']);
        if ($incomingId) {
            $query->where('incoming_id', $incomingId);
        }

        return Paginator::apply($query, $request, ['hs_code', 'item_series', 'country']);
    }

    public function create(Request $request, array $data): IncomingDetail
    {
        return DB::transaction(function () use ($data, $request) {
            $incoming = Incoming::findOrFail($data['incoming_id']);

            $detail = IncomingDetail::create([
                'incoming_id' => $incoming->id,
                'item_id' => $data['item_id'],
                'po_quantity' => isset($data['po_quantity']) ? (float) $data['po_quantity'] : null,
                'quantity' => (float) $data['quantity'],
                'remainder_quantity' => (float) $data['quantity'],
                'hs_code' => $data['hs_code'] ?? '',
                'country' => $data['country'] ?? '',
                'amount' => isset($data['amount']) ? (float) $data['amount'] : 0.0,
                'item_series' => $data['item_series'] ?? '',
            ]);

            // Pasangkan dengan ledger INCOMING_MATERIAL.
            $this->movementSvc->create([
                'movement_type' => MaterialMovement::TYPE_INCOMING_MATERIAL,
                'movement_date' => $incoming->incoming_date,
                'document_id' => $detail->id,
                'document_no' => $incoming->no,
                'item_id' => $detail->item_id,
                'quantity' => $detail->quantity,
                'movement_direction' => MaterialMovement::DIRECTION_IN,
                'location_from' => '',
                'location_to' => MaterialMovement::LOC_WAREHOUSE,
            ]);

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_CREATE,
                'Incoming Detail',
                "Created IncomingDetail #{$detail->id}",
            );

            return $detail->load(['item', 'incoming']);
        });
    }

    public function update(Request $request, array $data): IncomingDetail
    {
        return DB::transaction(function () use ($data, $request) {
            $detail = IncomingDetail::lockForUpdate()->findOrFail($data['id']);
            $incoming = Incoming::findOrFail($detail->incoming_id);

            // Save apa adanya, ledger row INCOMING_MATERIAL kita sync di
            // bawah supaya quantity-nya ikut update (perbaikan vs Go).
            $detail->fill([
                'item_id' => $data['item_id'] ?? $detail->item_id,
                'po_quantity' => array_key_exists('po_quantity', $data)
                    ? ($data['po_quantity'] !== null ? (float) $data['po_quantity'] : null)
                    : $detail->po_quantity,
                'hs_code' => $data['hs_code'] ?? $detail->hs_code,
                'country' => $data['country'] ?? $detail->country,
                'amount' => array_key_exists('amount', $data) ? (float) $data['amount'] : $detail->amount,
                'item_series' => $data['item_series'] ?? $detail->item_series,
            ]);

            if (array_key_exists('quantity', $data)) {
                $newQuantity = (float) $data['quantity'];
                $allocated = (float) $detail->quantity - (float) $detail->remainder_quantity;
                $detail->quantity = $newQuantity;
                // Pin remainder ke (qty baru - yang sudah dialokasi).
                // Kalau qty baru < allocated, remainder bisa negatif. Kita biarkan
                // sesuai semantik Go ("save and pray"); guard ada di FE.
                $detail->remainder_quantity = $newQuantity - $allocated;
            }

            $detail->save();

            // Sync qty ledger row dengan nilai baru.
            $this->movementSvc->updateQuantityForDocument(
                MaterialMovement::TYPE_INCOMING_MATERIAL,
                $detail->id,
                (float) $detail->quantity,
            );

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_UPDATE,
                'Incoming Detail',
                "Updated IncomingDetail #{$detail->id}",
            );

            return $detail->load(['item', 'incoming']);
        });
    }

    public function delete(Request $request, int $id): void
    {
        DB::transaction(function () use ($id, $request) {
            $detail = IncomingDetail::lockForUpdate()->findOrFail($id);

            // Match semantik Go: tolak hanya kalau ada FK row di tabel hilir.
            // Tidak cek remainder vs quantity karena data lama bisa miss-match
            // walau belum benar-benar dialokasi.
            $hasOutgoing = DB::table('outgoings_detail_incoming')
                ->where('incoming_detail_id', $id)
                ->exists();
            if ($hasOutgoing) {
                throw AppException::badRequest('Detail ini terkait data outgoing, tidak bisa dihapus.');
            }

            $hasProduction = DB::table('productions_detail_links')
                ->where('incoming_detail_id', $id)
                ->exists();
            if ($hasProduction) {
                throw AppException::badRequest('Detail ini terkait data production, tidak bisa dihapus.');
            }

            // Drop ledger row dulu (no FK cascade).
            $this->movementSvc->deleteForDocument(
                MaterialMovement::TYPE_INCOMING_MATERIAL,
                $id,
            );

            $detail->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_DELETE,
                'Incoming Detail',
                "Deleted IncomingDetail #{$id}",
            );
        });
    }
}
