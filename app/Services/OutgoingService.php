<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\MaterialMovement;
use App\Models\Order;
use App\Models\Outgoing;
use App\Models\OutgoingDetail;
use App\Models\Production;
use App\Support\Paginator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Outgoing (header) service.
 *
 * Outgoing punya 2 mode lewat field `feature`:
 *   - "Finished Goods": detail = FG, FIFO consume dari productions.remainder
 *     (logic di OutgoingDetailService). 1 detail = 1 production_id.
 *     Movement: OUTGOING_FG.
 *   - "Materials" / lainnya: belum di-wire (subcontract/return/dll),
 *     reserve untuk sesi terpisah.
 *
 * Header CRUD juga sekalian update orders.outgoing_id linkage.
 */
class OutgoingService
{
    public function __construct(
        private ActivityLogService $logSvc,
        private MaterialMovementService $movementSvc,
    ) {}

    public function findAll(?string $feature = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Outgoing::with([
            'company',
            'officeCode',
            'salesOrders.details.item',
            'details.item',
            'details.production.billOfMaterial.finishedGoodItem',
        ])->orderBy('id', 'desc');

        if ($feature) {
            $query->where('feature', $feature);
        }

        return $query->get();
    }

    public function findAllPagination(Request $request)
    {
        $query = Outgoing::with([
            'company',
            'officeCode',
            'salesOrders',
            'details.item',
        ]);
        if ($feature = $request->query('feature')) {
            $query->where('feature', $feature);
        }

        return Paginator::apply($query, $request, ['outgoing_no', 'peb_no']);
    }

    public function fetchDependency(): array
    {
        return [
            'company_responses' => \App\Models\Company::orderBy('name')->get(),
            'order_responses' => Order::with(['company', 'details.item'])
                ->where('feature', 'Sales')
                ->orderBy('id', 'desc')
                ->get(),
            'office_code_responses' => \App\Models\OfficeCode::orderBy('code')->get(),
            'production_responses' => Production::with(['billOfMaterial.finishedGoodItem'])
                ->where('feature', 'Finished Goods')
                ->where('remainder_quantity', '>', 0)
                ->orderBy('id', 'desc')
                ->get(),
        ];
    }

    public function create(Request $request, array $data): Outgoing
    {
        return DB::transaction(function () use ($data, $request) {
            $row = Outgoing::create($this->mapHeaderFields($data));

            $this->linkOrders($row, $data['order_id'] ?? []);

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_CREATE,
                'Outgoing',
                "Created Outgoing #{$row->id} ({$row->outgoing_no})",
            );

            return $row->load(['company', 'officeCode', 'salesOrders', 'details.item']);
        });
    }

    public function update(Request $request, array $data): Outgoing
    {
        return DB::transaction(function () use ($data, $request) {
            $row = Outgoing::lockForUpdate()->findOrFail($data['id']);

            $row->fill($this->mapHeaderFields($data, partial: true));
            $row->save();

            // Re-link sales orders if supplied (replace mode).
            if (array_key_exists('order_id', $data)) {
                Order::where('outgoing_id', $row->id)->update(['outgoing_id' => null]);
                $this->linkOrders($row, $data['order_id'] ?? []);
            }

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_UPDATE,
                'Outgoing',
                "Updated Outgoing #{$row->id}",
            );

            return $row->load(['company', 'officeCode', 'salesOrders', 'details.item']);
        });
    }

    public function delete(Request $request, int $id): void
    {
        DB::transaction(function () use ($id, $request) {
            $row = Outgoing::with('details')->lockForUpdate()->findOrFail($id);

            // FG outgoing: tiap detail rollback productions.remainder_quantity
            // dan hapus ledger OUTGOING_FG.
            $details = $row->details;
            $detailIds = $details->pluck('id')->all();

            if ($detailIds) {
                // Restore productions.remainder_quantity per detail.
                foreach ($details as $d) {
                    if ($d->production_id) {
                        DB::table('productions')
                            ->where('id', $d->production_id)
                            ->update([
                                'remainder_quantity' => DB::raw(
                                    'remainder_quantity + ' . ((string) $d->quantity),
                                ),
                            ]);
                    }
                }

                // Drop ledger row untuk detail ini.
                MaterialMovement::where('movement_type', MaterialMovement::TYPE_OUTGOING_FG)
                    ->whereIn('document_id', $detailIds)
                    ->delete();

                // Drop pivot row jaga-jaga (Materials FIFO biasanya nulis di sini).
                DB::table('outgoings_detail_incoming')
                    ->whereIn('outgoing_detail_id', $detailIds)
                    ->delete();

                OutgoingDetail::whereIn('id', $detailIds)->delete();
            }

            // Detach sales orders.
            Order::where('outgoing_id', $id)->update(['outgoing_id' => null]);

            $row->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_DELETE,
                'Outgoing',
                "Deleted Outgoing #{$id}",
            );
        });
    }

    private function mapHeaderFields(array $data, bool $partial = false): array
    {
        $fields = [
            'currency' => $data['currency'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'outgoing_no' => $data['outgoing_no'] ?? $data['no'] ?? null,
            'outgoing_date' => $this->parseDate($data['outgoing_date'] ?? $data['date'] ?? null),
            'feature' => $data['feature'] ?? null,
            // outgoing_type is non-null in schema (default '').
            'outgoing_type' => $data['outgoing_type'] ?? '',
            'peb_no' => $data['peb_no'] ?? '',
            'peb_date' => $this->parseDate($data['peb_date'] ?? null),
            'application_number' => $data['application_number'] ?? null,
            'application_registration_number' => $data['application_registration_number'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'registration_date' => $this->parseDate($data['registration_date'] ?? null),
            'office_code_id' => $data['office_code_id'] ?? null,
            'total_quantity' => isset($data['total_quantity']) ? (float) $data['total_quantity'] : null,
            'item_series' => $data['item_series'] ?? '',
            'travel_letter_number' => $data['travel_letter_number'] ?? '',
            'travel_letter_date' => $this->parseDate($data['travel_letter_date'] ?? null),
        ];

        // Untuk partial update, drop key yang tidak diberikan supaya tidak
        // overwrite kolom dengan null.
        if ($partial) {
            $present = [];
            foreach ($fields as $k => $v) {
                if (array_key_exists($k, $data) ||
                        ($k === 'outgoing_no' && array_key_exists('no', $data)) ||
                        ($k === 'outgoing_date' && array_key_exists('date', $data))) {
                    $present[$k] = $v;
                }
            }

            return $present;
        }

        return $fields;
    }

    private function linkOrders(Outgoing $outgoing, array $orderIds): void
    {
        if (empty($orderIds)) {
            return;
        }

        $found = Order::whereIn('id', $orderIds)->lockForUpdate()->get()->keyBy('id');
        foreach ($orderIds as $oid) {
            if (!isset($found[$oid])) {
                throw AppException::badRequest("Order with ID $oid not found");
            }
            $found[$oid]->outgoing_id = $outgoing->id;
            $found[$oid]->save();
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            throw AppException::badRequest("Invalid date format: {$value}");
        }
    }
}
