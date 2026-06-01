<?php

namespace App\Services;

use App\Models\MaterialMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writer ledger inventory (material_movements).
 *
 * Tabel material_movements adalah single source of truth untuk pergerakan
 * stok. Tiap perubahan qty (incoming, production consume/produce, outgoing,
 * outgoing-WIP, adjustment) harus diiringi 1 row movement.
 */
class MaterialMovementService
{
    /**
     * Create a single movement row.
     *
     * @param  array{
     *   movement_type: string,
     *   movement_date: \DateTimeInterface|string,
     *   document_id: int|string,
     *   document_no: string,
     *   item_id: int|string,
     *   quantity: float|string,
     *   movement_direction: string,
     *   location_from?: string,
     *   location_to?: string,
     *   parent_movement_id?: int|null,
     *   root_incoming_material_movement_id?: int|null,
     *   adjustment_type?: string|null,
     * }  $data
     */
    public function create(array $data): MaterialMovement
    {
        $date = $data['movement_date'] instanceof \DateTimeInterface
            ? $data['movement_date']
            : Carbon::parse((string) $data['movement_date']);

        return MaterialMovement::create([
            'movement_type' => $data['movement_type'],
            'movement_date' => $date,
            'document_id' => (int) $data['document_id'],
            'document_no' => (string) $data['document_no'],
            'item_id' => (int) $data['item_id'],
            'quantity' => (float) $data['quantity'],
            'movement_direction' => $data['movement_direction'],
            'location_from' => (string) ($data['location_from'] ?? ''),
            'location_to' => (string) ($data['location_to'] ?? ''),
            'parent_movement_id' => $data['parent_movement_id'] ?? null,
            'root_incoming_material_movement_id' => $data['root_incoming_material_movement_id'] ?? null,
            'adjustment_type' => $data['adjustment_type'] ?? null,
        ]);
    }

    /**
     * Update qty movement saat detail row di-edit.
     * Dipanggil IncomingDetail.update().
     */
    public function updateQuantityForDocument(string $type, int $documentId, float $newQuantity): void
    {
        MaterialMovement::where('movement_type', $type)
            ->where('document_id', $documentId)
            ->update(['quantity' => $newQuantity]);
    }

    /**
     * Hapus movement yang nempel di document tertentu.
     * Dipanggil saat Incoming/IncomingDetail/Outgoing/Production di-delete.
     */
    public function deleteForDocument(string $type, int $documentId): int
    {
        return MaterialMovement::where('movement_type', $type)
            ->where('document_id', $documentId)
            ->delete();
    }

    /**
     * Hapus movement untuk banyak document_id sekaligus.
     * Dipanggil Incoming.delete() untuk clear ledger semua child.
     */
    public function deleteForDocumentBatch(string $type, array $documentIds): int
    {
        if (empty($documentIds)) {
            return 0;
        }

        return MaterialMovement::where('movement_type', $type)
            ->whereIn('document_id', $documentIds)
            ->delete();
    }

    /**
     * Lookup parent movement (mis. PRODUCTION_CONSUME -> INCOMING_MATERIAL).
     */
    public function findParentMovement(int $movementId): ?MaterialMovement
    {
        return MaterialMovement::find($movementId);
    }

    /**
     * Cari INCOMING_MATERIAL movement untuk incoming detail tertentu.
     * Dipakai FIFO logic untuk dapat root movement.
     */
    public function findRootIncomingForDetail(int $incomingDetailId): ?MaterialMovement
    {
        return MaterialMovement::where('movement_type', MaterialMovement::TYPE_INCOMING_MATERIAL)
            ->where('document_id', $incomingDetailId)
            ->first();
    }
}
