<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\Incoming;
use App\Models\IncomingDetail;
use App\Models\MaterialMovement;
use App\Models\Order;
use App\Support\Paginator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Incoming (header) service.
 *
 * Catatan:
 *   - delete() men-unwind seluruh INCOMING_MATERIAL movement untuk semua
 *     child detail (perbaikan dari versi Go yg lupa hapus ledger).
 *   - update() hanya re-link orders.incoming_id; tidak menyentuh detail
 *     atau movement (itu lewat IncomingDetailService).
 *   - Semua write dibungkus 1 transaksi.
 */
class IncomingService
{
    public function __construct(
        private ActivityLogService $logSvc,
        private MaterialMovementService $movementSvc,
    ) {}

    public function findAll(?string $feature = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Incoming::with([
            'company',
            'officeCode',
            'details.item',
            'purchaseOrders',
        ])->orderBy('id', 'desc');

        if ($feature !== null && $feature !== '') {
            $query->where('feature', $feature);
        }

        return $query->get();
    }

    public function findAllPagination(Request $request, ?string $featureName = null)
    {
        $query = Incoming::with([
            'company',
            'officeCode',
            'details.item',
            'purchaseOrders',
        ]);

        if ($featureName !== null && $featureName !== '' && $featureName !== '-') {
            $query->where('feature', $featureName);
        }

        return Paginator::apply($query, $request, ['no', 'invoice_number']);
    }

    /**
     * @return array{ companies: \Illuminate\Database\Eloquent\Collection, orders: \Illuminate\Database\Eloquent\Collection, office_codes: \Illuminate\Database\Eloquent\Collection }
     */
    public function fetchDependency(): array
    {
        return [
            'company_responses' => \App\Models\Company::orderBy('name')->get(),
            'order_responses' => Order::with(['company', 'details.item'])
                ->where('feature', 'Purchase')
                ->orderBy('id', 'desc')
                ->get(),
            'office_code_responses' => \App\Models\OfficeCode::orderBy('code')->get(),
        ];
    }

    public function create(Request $request, array $data): Incoming
    {
        return DB::transaction(function () use ($data, $request) {
            $row = Incoming::create([
                'company_id' => $data['company_id'],
                'no' => $data['no'],
                'currency' => $data['currency'],
                'invoice_number' => $data['invoice_number'],
                'incoming_date' => $this->parseDate($data['date'] ?? $data['incoming_date'] ?? null),
                'invoice_date' => $this->parseDate($data['invoice_date'] ?? null),
                'customs_document_number' => $data['customs_document_number'] ?? null,
                'customs_document_date' => $this->parseDate($data['customs_document_date'] ?? null),
                'application_number' => $data['application_number'] ?? '',
                'office_code_id' => $data['office_code_id'] ?? null,
                'amount_item' => isset($data['amount_item']) ? (float) $data['amount_item'] : 0,
                'feature' => $data['feature'],
                'is_subcontract' => (bool) ($data['is_subcontract'] ?? false),
            ]);

            // Link order id ke incoming yang baru.
            $orderIds = $data['order_id'] ?? [];
            if ($orderIds) {
                $found = Order::whereIn('id', $orderIds)->lockForUpdate()->get()->keyBy('id');
                foreach ($orderIds as $oid) {
                    if (! isset($found[$oid])) {
                        throw AppException::badRequest("Order with ID $oid not found");
                    }
                    $found[$oid]->incoming_id = $row->id;
                    $found[$oid]->save();
                }
            }

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_CREATE,
                'Incoming',
                "Created Incoming #{$row->id} ({$row->no})",
            );

            return $row->load(['company', 'officeCode', 'purchaseOrders', 'details.item']);
        });
    }

    public function update(Request $request, array $data): Incoming
    {
        return DB::transaction(function () use ($data, $request) {
            $row = Incoming::findOrFail($data['id']);

            $row->fill([
                'company_id' => $data['company_id'] ?? $row->company_id,
                'no' => $data['no'] ?? $row->no,
                'currency' => $data['currency'] ?? $row->currency,
                'invoice_number' => $data['invoice_number'] ?? $row->invoice_number,
                'application_number' => $data['application_number'] ?? $row->application_number,
                'office_code_id' => array_key_exists('office_code_id', $data)
                    ? $data['office_code_id']
                    : $row->office_code_id,
                'amount_item' => array_key_exists('amount_item', $data)
                    ? (float) $data['amount_item']
                    : $row->amount_item,
                'feature' => $data['feature'] ?? $row->feature,
                'is_subcontract' => array_key_exists('is_subcontract', $data)
                    ? (bool) $data['is_subcontract']
                    : $row->is_subcontract,
            ]);

            if (array_key_exists('date', $data) || array_key_exists('incoming_date', $data)) {
                $row->incoming_date = $this->parseDate($data['date'] ?? $data['incoming_date'] ?? null);
            }
            if (array_key_exists('invoice_date', $data)) {
                $row->invoice_date = $this->parseDate($data['invoice_date']);
            }
            if (array_key_exists('customs_document_number', $data)) {
                $row->customs_document_number = $data['customs_document_number'];
            }
            if (array_key_exists('customs_document_date', $data)) {
                $row->customs_document_date = $this->parseDate($data['customs_document_date']);
            }

            $row->save();

            // Re-link orders if supplied: detach previous, attach new.
            if (array_key_exists('order_id', $data)) {
                Order::where('incoming_id', $row->id)->update(['incoming_id' => null]);
                $orderIds = $data['order_id'] ?? [];
                if ($orderIds) {
                    $found = Order::whereIn('id', $orderIds)->lockForUpdate()->get()->keyBy('id');
                    foreach ($orderIds as $oid) {
                        if (! isset($found[$oid])) {
                            throw AppException::badRequest("Order with ID $oid not found");
                        }
                        $found[$oid]->incoming_id = $row->id;
                        $found[$oid]->save();
                    }
                }
            }

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_UPDATE,
                'Incoming',
                "Updated Incoming #{$row->id} ({$row->no})",
            );

            return $row->load(['company', 'officeCode', 'purchaseOrders', 'details.item']);
        });
    }

    public function delete(Request $request, int $id): void
    {
        DB::transaction(function () use ($id, $request) {
            $row = Incoming::findOrFail($id);

            $details = IncomingDetail::where('incoming_id', $id)
                ->lockForUpdate()
                ->get();

            $detailIds = $details->pluck('id')->all();

            // Match semantik Go: tolak hanya kalau child detail masih ada
            // FK row hilir (production / outgoing).
            if ($detailIds) {
                $hasOutgoing = DB::table('outgoings_detail_incoming')
                    ->whereIn('incoming_detail_id', $detailIds)
                    ->exists();
                $hasProduction = DB::table('productions_detail_links')
                    ->whereIn('incoming_detail_id', $detailIds)
                    ->exists();

                if ($hasOutgoing || $hasProduction) {
                    throw AppException::badRequest(
                        'Terdapat data yang berelasi (outgoing/production), tolong di cek terlebih dahulu!',
                    );
                }
            }

            // Detach order yang nunjuk ke incoming ini.
            Order::where('incoming_id', $id)->update(['incoming_id' => null]);

            // Bug fix vs Go: unwind ledger INCOMING_MATERIAL untuk tiap detail.
            // Versi Go nge-bug, ledger jadi stale.
            if ($detailIds) {
                $this->movementSvc->deleteForDocumentBatch(
                    MaterialMovement::TYPE_INCOMING_MATERIAL,
                    $detailIds,
                );
            }

            // Drop child detail (no FK cascade), baru hapus header.
            IncomingDetail::where('incoming_id', $id)->delete();
            $row->delete();

            $this->logSvc->log(
                $request,
                ActivityLog::TYPE_DELETE,
                'Incoming',
                "Deleted Incoming #{$id}",
            );
        });
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
