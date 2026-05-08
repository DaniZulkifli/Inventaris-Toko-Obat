<?php

namespace App\Services;

use App\Enums\MedicineBatchStatus;
use App\Enums\MedicineClassification;
use App\Enums\MovementType;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StockService
{
    public function openingStock(MedicineBatch $batch, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): StockMovement
    {
        return $this->increase($batch, $quantity, MovementType::OpeningStock, $createdBy, [
            'reference_type' => 'opening_stock',
            ...$options,
        ]);
    }

    public function purchaseIn(MedicineBatch $batch, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): StockMovement
    {
        return $this->increase($batch, $quantity, MovementType::PurchaseIn, $createdBy, [
            'reference_type' => 'purchase_orders',
            ...$options,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\StockMovement>
     */
    public function saleOut(Medicine $medicine, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): Collection
    {
        $quantity = $this->normalizeQuantity($quantity);

        return DB::transaction(function () use ($medicine, $quantity, $createdBy, $options): Collection {
            if (! $medicine->is_active) {
                $this->fail('Obat nonaktif tidak dapat dijual.');
            }

            if ($manualBatch = $options['batch'] ?? null) {
                $batch = $manualBatch instanceof MedicineBatch
                    ? $manualBatch
                    : MedicineBatch::query()->findOrFail($manualBatch);
                $batch = $this->lockBatch($batch);

                $this->assertSaleableBatch($medicine, $batch, $quantity);

                return collect([
                    $this->decreaseLockedBatch($batch, $quantity, MovementType::SaleOut, $createdBy, [
                        'reference_type' => 'sales',
                        ...$options,
                    ]),
                ]);
            }

            $allocations = $this->allocateFefoBatches($medicine, $quantity, lock: true);

            return $allocations
                ->map(fn (array $allocation): StockMovement => $this->decreaseLockedBatch(
                    $allocation['batch'],
                    $allocation['quantity'],
                    MovementType::SaleOut,
                    $createdBy,
                    [
                        'reference_type' => 'sales',
                        ...$options,
                    ]
                ))
                ->values();
        });
    }

    public function usageOut(MedicineBatch $batch, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): StockMovement
    {
        return $this->decrease($batch, $quantity, MovementType::UsageOut, $createdBy, [
            'reference_type' => 'stock_usages',
            ...$options,
        ]);
    }

    public function adjustmentIn(MedicineBatch $batch, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): StockMovement
    {
        return $this->increase($batch, $quantity, MovementType::AdjustmentIn, $createdBy, [
            'reference_type' => 'stock_adjustments',
            ...$options,
        ]);
    }

    public function adjustmentOut(MedicineBatch $batch, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): StockMovement
    {
        return $this->decrease($batch, $quantity, MovementType::AdjustmentOut, $createdBy, [
            'reference_type' => 'stock_adjustments',
            ...$options,
        ]);
    }

    public function cancelUsage(MedicineBatch $batch, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): StockMovement
    {
        return $this->increase($batch, $quantity, MovementType::CancelUsage, $createdBy, [
            'reference_type' => 'stock_usages',
            ...$options,
        ]);
    }

    public function cancelAdjustment(MedicineBatch $batch, float|int|string $quantity, User|int|null $createdBy = null, array $options = []): StockMovement
    {
        $reverseOf = $this->movementValue($options['reverse_of'] ?? MovementType::AdjustmentOut);

        return match ($reverseOf) {
            MovementType::AdjustmentIn->value => $this->decrease($batch, $quantity, MovementType::CancelAdjustment, $createdBy, [
                'reference_type' => 'stock_adjustments',
                ...$options,
            ]),
            MovementType::AdjustmentOut->value => $this->increase($batch, $quantity, MovementType::CancelAdjustment, $createdBy, [
                'reference_type' => 'stock_adjustments',
                ...$options,
            ]),
            default => throw new InvalidArgumentException('cancelAdjustment hanya dapat membalik adjustment_in atau adjustment_out.'),
        };
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\MedicineBatch>
     */
    public function fefoBatches(Medicine $medicine, ?CarbonInterface $date = null): Collection
    {
        return $this->saleableBatchQuery($medicine, $date)->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{batch: \App\Models\MedicineBatch, quantity: float}>
     */
    public function allocateFefoBatches(Medicine $medicine, float|int|string $quantity, bool $lock = false, ?CarbonInterface $date = null): Collection
    {
        $quantity = $this->normalizeQuantity($quantity);
        $remaining = $quantity;
        $query = $this->saleableBatchQuery($medicine, $date);

        if ($lock) {
            $query->lockForUpdate();
        }

        $allocations = collect();

        foreach ($query->get() as $batch) {
            if ($remaining <= 0.0004) {
                break;
            }

            $available = $this->decimal($batch->current_stock);
            $taken = min($available, $remaining);

            if ($taken > 0) {
                $allocations->push([
                    'batch' => $batch,
                    'quantity' => $this->roundQuantity($taken),
                ]);
                $remaining = $this->roundQuantity($remaining - $taken);
            }
        }

        if ($remaining > 0.0004) {
            $this->fail('Stok jual tidak cukup untuk transaksi ini.');
        }

        return $allocations;
    }

    public function saleableStock(Medicine $medicine, ?CarbonInterface $date = null): string
    {
        return $this->formatQuantity((float) $this->saleableBatchQuery($medicine, $date)->sum('current_stock'));
    }

    public function sellingPriceFor(MedicineBatch $batch): string
    {
        $batch->loadMissing('medicine');

        $price = $batch->selling_price !== null
            ? $batch->selling_price
            : $batch->medicine->selling_price;

        return $this->formatMoney($price);
    }

    public function isSaleableBatch(MedicineBatch $batch, ?CarbonInterface $date = null): bool
    {
        $batch->loadMissing('medicine');
        $today = $this->currentDate($date);
        $status = $this->statusValue($batch->status);

        if (! $batch->medicine->is_active || $status !== MedicineBatchStatus::Available->value) {
            return false;
        }

        if ($this->decimal($batch->current_stock) <= 0) {
            return false;
        }

        if ($batch->expiry_date === null) {
            return $this->allowsBatchWithoutExpiry($batch->medicine);
        }

        return $batch->expiry_date->toDateString() > $today->toDateString();
    }

    public function resolveBatchStatus(MedicineBatch $batch, ?CarbonInterface $date = null): MedicineBatchStatus
    {
        $today = $this->currentDate($date);

        if ($this->statusValue($batch->status) === MedicineBatchStatus::Quarantined->value) {
            return MedicineBatchStatus::Quarantined;
        }

        if ($this->decimal($batch->current_stock) <= 0) {
            return MedicineBatchStatus::Depleted;
        }

        if ($batch->expiry_date && $batch->expiry_date->toDateString() <= $today->toDateString()) {
            return MedicineBatchStatus::Expired;
        }

        return MedicineBatchStatus::Available;
    }

    private function increase(MedicineBatch $batch, float|int|string $quantity, MovementType $type, User|int|null $createdBy, array $options): StockMovement
    {
        $quantity = $this->normalizeQuantity($quantity);

        return DB::transaction(fn (): StockMovement => $this->increaseLockedBatch($batch, $quantity, $type, $createdBy, $options));
    }

    private function decrease(MedicineBatch $batch, float|int|string $quantity, MovementType $type, User|int|null $createdBy, array $options): StockMovement
    {
        $quantity = $this->normalizeQuantity($quantity);

        return DB::transaction(fn (): StockMovement => $this->decreaseLockedBatch($batch, $quantity, $type, $createdBy, $options));
    }

    private function increaseLockedBatch(MedicineBatch $batch, float $quantity, MovementType $type, User|int|null $createdBy, array $options): StockMovement
    {
        $lockedBatch = $this->lockBatch($batch);
        $stockBefore = $this->decimal($lockedBatch->current_stock);
        $stockAfter = $this->roundQuantity($stockBefore + $quantity);

        $lockedBatch->current_stock = $this->formatQuantity($stockAfter);
        $lockedBatch->status = $this->resolveBatchStatus($lockedBatch);
        $lockedBatch->save();

        return $this->createMovement($lockedBatch, $type, $quantity, 0, $stockBefore, $stockAfter, $createdBy, $options);
    }

    private function decreaseLockedBatch(MedicineBatch $batch, float $quantity, MovementType $type, User|int|null $createdBy, array $options): StockMovement
    {
        $lockedBatch = $this->lockBatch($batch);
        $stockBefore = $this->decimal($lockedBatch->current_stock);

        if ($stockBefore < $quantity) {
            $this->fail('Stok batch tidak cukup untuk transaksi ini.');
        }

        $stockAfter = $this->roundQuantity($stockBefore - $quantity);

        $lockedBatch->current_stock = $this->formatQuantity($stockAfter);
        $lockedBatch->status = $this->resolveBatchStatus($lockedBatch);
        $lockedBatch->save();

        return $this->createMovement($lockedBatch, $type, 0, $quantity, $stockBefore, $stockAfter, $createdBy, $options);
    }

    private function createMovement(
        MedicineBatch $batch,
        MovementType $type,
        float $quantityIn,
        float $quantityOut,
        float $stockBefore,
        float $stockAfter,
        User|int|null $createdBy,
        array $options
    ): StockMovement {
        return StockMovement::query()->create([
            'medicine_id' => $batch->medicine_id,
            'medicine_batch_id' => $batch->id,
            'movement_type' => $type,
            'reference_type' => $options['reference_type'] ?? null,
            'reference_id' => $options['reference_id'] ?? null,
            'quantity_in' => $this->formatQuantity($quantityIn),
            'quantity_out' => $this->formatQuantity($quantityOut),
            'stock_before' => $this->formatQuantity($stockBefore),
            'stock_after' => $this->formatQuantity($stockAfter),
            'unit_cost_snapshot' => $this->formatMoney($options['unit_cost_snapshot'] ?? $batch->purchase_price),
            'description' => $options['description'] ?? null,
            'created_by' => $this->createdById($createdBy),
        ]);
    }

    private function saleableBatchQuery(Medicine $medicine, ?CarbonInterface $date = null): Builder
    {
        $today = $this->currentDate($date)->toDateString();

        return MedicineBatch::query()
            ->with('medicine')
            ->where('medicine_id', $medicine->id)
            ->where('status', MedicineBatchStatus::Available->value)
            ->where('current_stock', '>', 0)
            ->where(function (Builder $query) use ($medicine, $today): void {
                $query->where('expiry_date', '>', $today);

                if ($this->allowsBatchWithoutExpiry($medicine)) {
                    $query->orWhereNull('expiry_date');
                }
            })
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    private function assertSaleableBatch(Medicine $medicine, MedicineBatch $batch, float $quantity): void
    {
        if ((int) $batch->medicine_id !== (int) $medicine->id) {
            $this->fail('Batch tidak sesuai dengan obat yang dijual.');
        }

        if (! $this->isSaleableBatch($batch)) {
            $this->fail('Batch tidak dapat dipilih untuk penjualan.');
        }

        if ($this->decimal($batch->current_stock) < $quantity) {
            $this->fail('Stok batch tidak cukup untuk transaksi ini.');
        }
    }

    private function lockBatch(MedicineBatch $batch): MedicineBatch
    {
        return MedicineBatch::query()
            ->with('medicine')
            ->whereKey($batch->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function allowsBatchWithoutExpiry(Medicine $medicine): bool
    {
        return in_array($this->classificationValue($medicine->classification), [
            MedicineClassification::Alkes->value,
            MedicineClassification::Other->value,
        ], true);
    }

    private function currentDate(?CarbonInterface $date = null): CarbonInterface
    {
        return $date ?: Carbon::today(config('app.timezone'));
    }

    private function createdById(User|int|null $createdBy): int
    {
        if ($createdBy instanceof User) {
            return $createdBy->id;
        }

        if ($createdBy) {
            return (int) $createdBy;
        }

        if ($id = Auth::id()) {
            return (int) $id;
        }

        throw new InvalidArgumentException('created_by wajib diisi untuk mencatat stock movement.');
    }

    private function normalizeQuantity(float|int|string $quantity): float
    {
        $quantity = $this->roundQuantity($quantity);

        if ($quantity <= 0) {
            $this->fail('Quantity harus lebih dari 0.');
        }

        return $quantity;
    }

    private function roundQuantity(float|int|string $quantity): float
    {
        return round((float) $quantity, 3);
    }

    private function decimal(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), 3);
    }

    private function formatQuantity(float|int|string $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function formatMoney(float|int|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function movementValue(MovementType|string $type): string
    {
        return $type instanceof MovementType ? $type->value : $type;
    }

    private function statusValue(MedicineBatchStatus|string $status): string
    {
        return $status instanceof MedicineBatchStatus ? $status->value : $status;
    }

    private function classificationValue(MedicineClassification|string $classification): string
    {
        return $classification instanceof MedicineClassification ? $classification->value : $classification;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'stock' => $message,
        ]);
    }
}
