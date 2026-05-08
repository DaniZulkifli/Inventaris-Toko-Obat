<?php

namespace App\Services;

use App\Enums\MedicineBatchStatus;
use App\Enums\MedicineClassification;
use App\Enums\PurchaseOrderStatus;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function createDraft(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user): PurchaseOrder {
            $this->ensureSupplierActive((int) $data['supplier_id']);

            $purchaseOrder = PurchaseOrder::query()->create([
                'code' => $data['code'] ?? $this->generateCode($data['order_date']),
                'supplier_id' => $data['supplier_id'],
                'created_by' => $user->id,
                'received_by' => null,
                'order_date' => $data['order_date'],
                'received_date' => null,
                'status' => PurchaseOrderStatus::Draft,
                'subtotal' => '0.00',
                'discount' => $this->formatMoney($data['discount'] ?? 0),
                'total_amount' => '0.00',
                'notes' => $data['notes'] ?? null,
            ]);

            $totals = $this->syncItems($purchaseOrder, $data['items']);
            $this->updateTotals($purchaseOrder, $totals['subtotal'], $data['discount'] ?? 0);

            return $purchaseOrder->refresh()->load(['supplier', 'items.medicine']);
        });
    }

    public function updateDraft(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $this->ensureDraft($purchaseOrder);

        return DB::transaction(function () use ($purchaseOrder, $data): PurchaseOrder {
            $this->ensureSupplierActive((int) $data['supplier_id']);

            $purchaseOrder->update([
                'supplier_id' => $data['supplier_id'],
                'order_date' => $data['order_date'],
                'discount' => $this->formatMoney($data['discount'] ?? 0),
                'notes' => $data['notes'] ?? null,
            ]);

            $purchaseOrder->items()->delete();
            $totals = $this->syncItems($purchaseOrder, $data['items']);
            $this->updateTotals($purchaseOrder, $totals['subtotal'], $data['discount'] ?? 0);

            return $purchaseOrder->refresh()->load(['supplier', 'items.medicine']);
        });
    }

    public function deleteDraft(PurchaseOrder $purchaseOrder): void
    {
        $this->ensureDraft($purchaseOrder);

        $purchaseOrder->delete();
    }

    public function receive(PurchaseOrder $purchaseOrder, User $user): PurchaseOrder
    {
        $this->ensureDraft($purchaseOrder);

        return DB::transaction(function () use ($purchaseOrder, $user): PurchaseOrder {
            $purchaseOrder = PurchaseOrder::query()
                ->with(['supplier', 'items.medicine'])
                ->whereKey($purchaseOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($purchaseOrder);

            if (! $purchaseOrder->supplier->is_active) {
                $this->fail('supplier_id', 'Supplier nonaktif tidak dapat digunakan untuk penerimaan stok.');
            }

            if ($purchaseOrder->items->isEmpty()) {
                $this->fail('items', 'Purchase order minimal memiliki satu item.');
            }

            $receivedDate = Carbon::today(config('app.timezone'))->toDateString();

            foreach ($purchaseOrder->items as $index => $item) {
                $this->validateStoredItem($item, $index);

                $batch = $this->findBatchForItem($item)
                    ?? $this->createBatchForItem($purchaseOrder, $item, $receivedDate);

                if ($batch->exists) {
                    $batch->forceFill([
                        'supplier_id' => $purchaseOrder->supplier_id,
                        'purchase_price' => $this->formatMoney($item->unit_cost),
                        'received_date' => $receivedDate,
                    ])->save();
                }

                $item->update(['medicine_batch_id' => $batch->id]);

                $this->stockService->purchaseIn($batch, $item->quantity, $user, [
                    'reference_id' => $purchaseOrder->id,
                    'unit_cost_snapshot' => $item->unit_cost,
                    'description' => "{$purchaseOrder->code} {$item->medicine->name}",
                ]);
            }

            $purchaseOrder->update([
                'received_by' => $user->id,
                'received_date' => $receivedDate,
                'status' => PurchaseOrderStatus::Received,
            ]);

            return $purchaseOrder->refresh()->load(['supplier', 'receiver', 'items.medicine', 'items.batch']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float}
     */
    private function syncItems(PurchaseOrder $purchaseOrder, array $items): array
    {
        $subtotal = 0.0;

        foreach (array_values($items) as $index => $item) {
            $medicine = Medicine::query()->findOrFail($item['medicine_id']);

            if (! $medicine->is_active) {
                $this->fail("items.{$index}.medicine_id", 'Obat nonaktif tidak dapat dipilih pada purchase order.');
            }

            $expiryDate = filled($item['expiry_date'] ?? null)
                ? Carbon::parse($item['expiry_date'])->toDateString()
                : null;

            $this->validateExpiryRule($medicine, $expiryDate, "items.{$index}.expiry_date");

            $quantity = $this->quantity($item['quantity'] ?? 0);
            $unitCost = $this->money($item['unit_cost'] ?? 0);

            if ($quantity <= 0) {
                $this->fail("items.{$index}.quantity", 'Quantity harus lebih dari 0.');
            }

            if ($unitCost < 0) {
                $this->fail("items.{$index}.unit_cost", 'Unit cost tidak boleh negatif.');
            }

            $itemSubtotal = $this->money($quantity * $unitCost);
            $subtotal = $this->money($subtotal + $itemSubtotal);

            PurchaseOrderItem::query()->create([
                'purchase_order_id' => $purchaseOrder->id,
                'medicine_id' => $medicine->id,
                'medicine_batch_id' => null,
                'batch_number' => $this->batchNumber($item['batch_number'] ?? null, $purchaseOrder->order_date),
                'expiry_date' => $expiryDate,
                'quantity' => $this->formatQuantity($quantity),
                'unit_cost' => $this->formatMoney($unitCost),
                'subtotal' => $this->formatMoney($itemSubtotal),
            ]);
        }

        return ['subtotal' => $subtotal];
    }

    private function updateTotals(PurchaseOrder $purchaseOrder, float $subtotal, float|int|string|null $discount): void
    {
        $discount = $this->money($discount ?? 0);

        if ($discount < 0) {
            $this->fail('discount', 'Diskon tidak boleh negatif.');
        }

        if ($discount > $subtotal) {
            $this->fail('discount', 'Diskon tidak boleh lebih besar dari subtotal purchase order.');
        }

        $purchaseOrder->update([
            'subtotal' => $this->formatMoney($subtotal),
            'discount' => $this->formatMoney($discount),
            'total_amount' => $this->formatMoney($subtotal - $discount),
        ]);
    }

    private function validateStoredItem(PurchaseOrderItem $item, int $index): void
    {
        $item->loadMissing('medicine');

        if (! $item->medicine->is_active) {
            $this->fail("items.{$index}.medicine_id", 'Obat nonaktif tidak dapat diterima.');
        }

        if (blank($item->batch_number)) {
            $this->fail("items.{$index}.batch_number", 'Batch number wajib diisi.');
        }

        $this->validateExpiryRule(
            $item->medicine,
            $item->expiry_date?->toDateString(),
            "items.{$index}.expiry_date"
        );

        if ($this->quantity($item->quantity) <= 0) {
            $this->fail("items.{$index}.quantity", 'Quantity harus lebih dari 0.');
        }

        if ($this->money($item->unit_cost) < 0) {
            $this->fail("items.{$index}.unit_cost", 'Unit cost tidak boleh negatif.');
        }
    }

    private function validateExpiryRule(Medicine $medicine, ?string $expiryDate, string $key): void
    {
        $allowsWithoutExpiry = in_array($this->classificationValue($medicine->classification), [
            MedicineClassification::Alkes->value,
            MedicineClassification::Other->value,
        ], true);

        if (! $allowsWithoutExpiry && blank($expiryDate)) {
            $this->fail($key, 'Expiry date wajib diisi untuk obat selain alkes atau other.');
        }

        if (filled($expiryDate) && Carbon::parse($expiryDate)->toDateString() <= Carbon::today(config('app.timezone'))->toDateString()) {
            $this->fail($key, 'Expiry date penerimaan stok harus lebih besar dari tanggal hari ini.');
        }
    }

    private function findBatchForItem(PurchaseOrderItem $item): ?MedicineBatch
    {
        return MedicineBatch::query()
            ->where('medicine_id', $item->medicine_id)
            ->where('batch_number', $item->batch_number)
            ->when(
                $item->expiry_date,
                fn ($query) => $query->whereDate('expiry_date', $item->expiry_date->toDateString()),
                fn ($query) => $query->whereNull('expiry_date')
            )
            ->lockForUpdate()
            ->first();
    }

    private function createBatchForItem(PurchaseOrder $purchaseOrder, PurchaseOrderItem $item, string $receivedDate): MedicineBatch
    {
        return MedicineBatch::query()->create([
            'medicine_id' => $item->medicine_id,
            'supplier_id' => $purchaseOrder->supplier_id,
            'batch_number' => $item->batch_number,
            'expiry_date' => $item->expiry_date?->toDateString(),
            'purchase_price' => $this->formatMoney($item->unit_cost),
            'selling_price' => null,
            'initial_stock' => '0.000',
            'current_stock' => '0.000',
            'received_date' => $receivedDate,
            'status' => MedicineBatchStatus::Depleted,
            'notes' => "Dibuat dari {$purchaseOrder->code}",
        ]);
    }

    private function ensureSupplierActive(int $supplierId): void
    {
        $isActive = Supplier::query()
            ->whereKey($supplierId)
            ->where('is_active', true)
            ->exists();

        if (! $isActive) {
            $this->fail('supplier_id', 'Supplier aktif wajib dipilih.');
        }
    }

    private function ensureDraft(PurchaseOrder $purchaseOrder): void
    {
        if ($this->statusValue($purchaseOrder->status) !== PurchaseOrderStatus::Draft->value) {
            $this->fail('status', 'Purchase order received tidak dapat diubah, dihapus, atau diterima ulang.');
        }
    }

    private function batchNumber(?string $batchNumber, mixed $date): string
    {
        $batchNumber = trim((string) $batchNumber);

        if ($batchNumber !== '') {
            return $batchNumber;
        }

        return $this->generateAutoBatchNumber($date);
    }

    private function generateCode(string $date): string
    {
        $prefix = 'PO-'.Carbon::parse($date)->format('Ymd').'-';
        $lastCode = PurchaseOrder::query()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');
        $nextNumber = $lastCode ? ((int) substr($lastCode, -4)) + 1 : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function generateAutoBatchNumber(mixed $date): string
    {
        $prefix = 'AUTO-'.Carbon::parse($date)->format('Ymd').'-';
        $lastBatchNumber = collect([
            MedicineBatch::query()
                ->where('batch_number', 'like', $prefix.'%')
                ->orderByDesc('batch_number')
                ->value('batch_number'),
            PurchaseOrderItem::query()
                ->where('batch_number', 'like', $prefix.'%')
                ->orderByDesc('batch_number')
                ->value('batch_number'),
        ])->filter()->sortDesc()->first();
        $nextNumber = $lastBatchNumber ? ((int) substr($lastBatchNumber, -4)) + 1 : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function statusValue(PurchaseOrderStatus|string $status): string
    {
        return $status instanceof PurchaseOrderStatus ? $status->value : $status;
    }

    private function classificationValue(MedicineClassification|string $classification): string
    {
        return $classification instanceof MedicineClassification ? $classification->value : $classification;
    }

    private function quantity(float|int|string $value): float
    {
        return round((float) $value, 3);
    }

    private function money(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function formatQuantity(float|int|string $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function formatMoney(float|int|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([
            $key => $message,
        ]);
    }
}
