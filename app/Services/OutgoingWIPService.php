<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\IncomingDetail;
use App\Models\MaterialMovement;
use App\Models\OutgoingWIP;
use App\Models\OutgoingWIPDetail;
use App\Support\Paginator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OutgoingWIP (header) service.
 *
 * Header berisi no, date, type. Logic alokasi ada di OutgoingWIPDetailService.
 *
 * Pada delete header:
 *   - tolak kalau ada child detail yang sudah dipakai PRODUCTION_CONSUME
 *   - jika tidak, restore incomings_details.remainder_quantity untuk tiap
 *     OUTGOING_WIP movement, drop movement + child detail + header.
 */
class OutgoingWIPService
{
    public function __construct(
        private ActivityLogService $logSvc,
    ) {}

    public function findAll(): \Illuminate\Database\Eloquent\Collection
    {
        return OutgoingWIP::with(['details.item', 'details.incomingDetail.incoming'])
            ->orderBy('id', 'desc')
            ->get();
    }

    public function findAllPagination(Request $request)
    {
        $query = OutgoingWIP::with(['details.item']);

        return Paginator::apply($query, $request, ['no', 'type']);
    }

    public function create(Request $request, array $data): OutgoingWIP
    {
        return DB::transaction(function () use ($data, $request) {
            $row = OutgoingWIP::create([
                'no' => $data['no'],
                'date' => Carbon::parse($data['date']),
                'type' => $data['type'],
            ]);

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_CREATE,
                'Outgoing WIP',
                "Created OutgoingWIP #{$row->id} ({$row->no})",
            );

            return $row;
        });
    }

    public function update(Request $request, array $data): OutgoingWIP
    {
        return DB::transaction(function () use ($data, $request) {
            $row = OutgoingWIP::lockForUpdate()->findOrFail($data['id']);

            if (array_key_exists('no', $data)) {
                $row->no = $data['no'];
            }
            if (array_key_exists('date', $data)) {
                $row->date = Carbon::parse($data['date']);
            }
            if (array_key_exists('type', $data)) {
                $row->type = $data['type'];
            }
            $row->save();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_UPDATE,
                'Outgoing WIP',
                "Updated OutgoingWIP #{$row->id}",
            );

            return $row;
        });
    }

    public function delete(Request $request, int $id): void
    {
        DB::transaction(function () use ($id, $request) {
            $row = OutgoingWIP::with('details')->lockForUpdate()->findOrFail($id);

            $details = $row->details;
            $detailIds = $details->pluck('id')->all();

            if ($detailIds) {
                // Tolak kalau material sudah dipakai production.
                $usedInProduction = DB::table('material_movements as mm_consume')
                    ->join('material_movements as mm_wip', 'mm_consume.parent_movement_id', '=', 'mm_wip.id')
                    ->whereIn('mm_wip.document_id', $detailIds)
                    ->where('mm_wip.movement_type', MaterialMovement::TYPE_OUTGOING_WIP)
                    ->where('mm_consume.movement_type', MaterialMovement::TYPE_PRODUCTION_CONSUME)
                    ->exists();

                if ($usedInProduction) {
                    throw AppException::conflict(
                        'Tidak dapat menghapus Outgoing WIP. Material sudah digunakan di production.',
                    );
                }

                // Restore incoming detail remainder per OUTGOING_WIP movement.
                $wipMovements = MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_WIP)
                    ->whereIn('document_id', $detailIds)
                    ->get();

                foreach ($wipMovements as $mv) {
                    if (!$mv->parent_movement_id) {
                        continue;
                    }
                    $incomingMovement = MaterialMovement::find($mv->parent_movement_id);
                    if (!$incomingMovement) {
                        continue;
                    }
                    DB::table('incomings_details')
                        ->where('id', $incomingMovement->document_id)
                        ->update([
                            'remainder_quantity' => DB::raw(
                                'remainder_quantity + ' . ((string) $mv->quantity),
                            ),
                        ]);
                }

                MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_WIP)
                    ->whereIn('document_id', $detailIds)
                    ->delete();

                OutgoingWIPDetail::whereIn('id', $detailIds)->delete();
            }

            $row->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_DELETE,
                'Outgoing WIP',
                "Deleted OutgoingWIP #{$id}",
            );
        });
    }
}
