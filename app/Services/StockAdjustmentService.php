<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\StockAdjustmentStatus;
use App\Models\MedicineBatch;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function createDraft(array $data, User $user): StockAdjustment
    {
        return DB::transaction(function () use ($data, $user): StockAdjustment {
            $adjustment = StockAdjustment::query()->create([
                'code' => $this->generateCode($data['adjustment_date']),
                'created_by' => $user->id,
                'approved_by' => null,
                'adjustment_date' => $data['adjustment_date'],
                'status' => StockAdjustmentStatus::Draft,
                'reason' => $data['reason'],
            ]);

            $this->syncItems($adjustment, $data['items']);

            return $adjustment->refresh()->load(['creator', 'items.medicine', 'items.batch']);
        });
    }

    public function updateDraft(StockAdjustment $adjustment, array $data): StockAdjustment
    {
        $this->ensureDraft($adjustment);

        return DB::transaction(function () use ($adjustment, $data): StockAdjustment {
            $adjustment->update([
                'adjustment_date' => $data['adjustment_date'],
                'reason' => $data['reason'],
            ]);

            $adjustment->items()->delete();
            $this->syncItems($adjustment, $data['items']);

            return $adjustment->refresh()->load(['creator', 'items.medicine', 'items.batch']);
        });
    }

    public function approve(StockAdjustment $adjustment, User $user): StockAdjustment
    {
        $this->ensureDraft($adjustment);

        return DB::transaction(function () use ($adjustment, $user): StockAdjustment {
            $adjustment = StockAdjustment::query()
                ->with(['items.batch.medicine'])
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($adjustment);

            if ($adjustment->items->isEmpty()) {
                $this->fail('items', 'Adjustment minimal memiliki satu item.');
            }

            foreach ($adjustment->items as $item) {
                $difference = $this->quantity($item->counted_stock) - $this->quantity($item->system_stock);
                $difference = $this->quantity($difference);

                $item->update([
                    'difference' => $this->formatQuantity($difference),
                ]);

                if (abs($difference) <= 0.0004) {
                    continue;
                }

                if ($difference > 0) {
                    $this->stockService->adjustmentIn($item->batch, $difference, $user, [
                        'reference_id' => $adjustment->id,
                        'unit_cost_snapshot' => $item->cost_snapshot,
                        'description' => "{$adjustment->code} selisih {$item->medicine->name}",
                    ]);
                    continue;
                }

                $this->stockService->adjustmentOut($item->batch, abs($difference), $user, [
                    'reference_id' => $adjustment->id,
                    'unit_cost_snapshot' => $item->cost_snapshot,
                    'description' => "{$adjustment->code} selisih {$item->medicine->name}",
                ]);
            }

            $adjustment->update([
                'approved_by' => $user->id,
                'status' => StockAdjustmentStatus::Approved,
            ]);

            return $adjustment->refresh()->load(['creator', 'approver', 'items.medicine', 'items.batch']);
        });
    }

    public function cancelApproved(StockAdjustment $adjustment, User $user, string $reason): StockAdjustment
    {
        if ($this->statusValue($adjustment->status) !== StockAdjustmentStatus::Approved->value) {
            $this->fail('status', 'Hanya stock adjustment approved yang dapat dibatalkan.');
        }

        return DB::transaction(function () use ($adjustment, $user, $reason): StockAdjustment {
            $adjustment = StockAdjustment::query()
                ->with(['items.batch.medicine'])
                ->whereKey($adjustment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->statusValue($adjustment->status) !== StockAdjustmentStatus::Approved->value) {
                $this->fail('status', 'Hanya stock adjustment approved yang dapat dibatalkan.');
            }

            foreach ($adjustment->items as $item) {
                $difference = $this->quantity($item->difference);

                if (abs($difference) <= 0.0004) {
                    continue;
                }

                if ($difference > 0) {
                    $this->stockService->cancelAdjustment($item->batch, $difference, $user, [
                        'reference_id' => $adjustment->id,
                        'reverse_of' => MovementType::AdjustmentIn,
                        'unit_cost_snapshot' => $item->cost_snapshot,
                        'description' => "{$adjustment->code} dibatalkan: {$reason}",
                    ]);
                    continue;
                }

                $this->stockService->cancelAdjustment($item->batch, abs($difference), $user, [
                    'reference_id' => $adjustment->id,
                    'reverse_of' => MovementType::AdjustmentOut,
                    'unit_cost_snapshot' => $item->cost_snapshot,
                    'description' => "{$adjustment->code} dibatalkan: {$reason}",
                ]);
            }

            $adjustment->update([
                'status' => StockAdjustmentStatus::Cancelled,
                'reason' => trim($adjustment->reason."\nPembatalan: ".$reason),
            ]);

            return $adjustment->refresh()->load(['creator', 'approver', 'items.medicine', 'items.batch']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(StockAdjustment $adjustment, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            $batch = MedicineBatch::query()
                ->with('medicine')
                ->findOrFail($item['medicine_batch_id']);

            $countedStock = $this->quantity($item['counted_stock'] ?? 0);

            if ($countedStock < 0) {
                $this->fail("items.{$index}.counted_stock", 'Counted stock tidak boleh negatif.');
            }

            $systemStock = $this->quantity($batch->current_stock);
            $difference = $this->quantity($countedStock - $systemStock);

            StockAdjustmentItem::query()->create([
                'stock_adjustment_id' => $adjustment->id,
                'medicine_id' => $batch->medicine_id,
                'medicine_batch_id' => $batch->id,
                'system_stock' => $this->formatQuantity($systemStock),
                'counted_stock' => $this->formatQuantity($countedStock),
                'difference' => $this->formatQuantity($difference),
                'cost_snapshot' => $this->formatMoney($batch->purchase_price),
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    private function ensureDraft(StockAdjustment $adjustment): void
    {
        if ($this->statusValue($adjustment->status) !== StockAdjustmentStatus::Draft->value) {
            $this->fail('status', 'Stock adjustment approved atau cancelled tidak dapat diubah.');
        }
    }

    private function generateCode(string $date): string
    {
        $prefix = 'ADJ-'.Carbon::parse($date)->format('Ymd').'-';
        $lastCode = StockAdjustment::query()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');
        $nextNumber = $lastCode ? ((int) substr($lastCode, -4)) + 1 : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function statusValue(StockAdjustmentStatus|string $status): string
    {
        return $status instanceof StockAdjustmentStatus ? $status->value : $status;
    }

    private function quantity(float|int|string $value): float
    {
        return round((float) $value, 3);
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
