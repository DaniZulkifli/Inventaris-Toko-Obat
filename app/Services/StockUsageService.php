<?php

namespace App\Services;

use App\Enums\StockUsageStatus;
use App\Models\MedicineBatch;
use App\Models\StockUsage;
use App\Models\StockUsageItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockUsageService
{
    public function __construct(private readonly StockService $stockService)
    {
    }

    public function createDraft(array $data, User $user): StockUsage
    {
        return DB::transaction(function () use ($data, $user): StockUsage {
            $stockUsage = StockUsage::query()->create([
                'code' => $this->generateCode($data['usage_date']),
                'created_by' => $user->id,
                'completed_by' => null,
                'usage_date' => $data['usage_date'],
                'usage_type' => $data['usage_type'],
                'status' => StockUsageStatus::Draft,
                'estimated_total_cost' => '0.00',
                'reason' => $data['reason'],
            ]);

            $estimatedTotal = $this->syncItems($stockUsage, $data['items']);
            $stockUsage->update(['estimated_total_cost' => $this->formatMoney($estimatedTotal)]);

            return $stockUsage->refresh()->load(['creator', 'items.medicine', 'items.batch']);
        });
    }

    public function updateDraft(StockUsage $stockUsage, array $data): StockUsage
    {
        $this->ensureDraft($stockUsage);

        return DB::transaction(function () use ($stockUsage, $data): StockUsage {
            $stockUsage->update([
                'usage_date' => $data['usage_date'],
                'usage_type' => $data['usage_type'],
                'reason' => $data['reason'],
            ]);

            $stockUsage->items()->delete();
            $estimatedTotal = $this->syncItems($stockUsage, $data['items']);
            $stockUsage->update(['estimated_total_cost' => $this->formatMoney($estimatedTotal)]);

            return $stockUsage->refresh()->load(['creator', 'items.medicine', 'items.batch']);
        });
    }

    public function deleteDraft(StockUsage $stockUsage): void
    {
        $this->ensureDraft($stockUsage);

        $stockUsage->delete();
    }

    public function complete(StockUsage $stockUsage, User $user): StockUsage
    {
        $this->ensureDraft($stockUsage);

        return DB::transaction(function () use ($stockUsage, $user): StockUsage {
            $stockUsage = StockUsage::query()
                ->with(['items.batch.medicine'])
                ->whereKey($stockUsage->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDraft($stockUsage);

            if ($stockUsage->items->isEmpty()) {
                $this->fail('items', 'Stock usage minimal memiliki satu item.');
            }

            $estimatedTotal = 0.0;

            foreach ($stockUsage->items as $index => $item) {
                $this->validateItem($item->batch, $item->quantity, "items.{$index}.quantity");
                $estimatedTotal = $this->money($estimatedTotal + $this->money($item->estimated_cost));

                $this->stockService->usageOut($item->batch, $item->quantity, $user, [
                    'reference_id' => $stockUsage->id,
                    'unit_cost_snapshot' => $item->cost_snapshot,
                    'description' => "{$stockUsage->code} {$item->medicine->name}",
                ]);
            }

            $stockUsage->update([
                'completed_by' => $user->id,
                'status' => StockUsageStatus::Completed,
                'estimated_total_cost' => $this->formatMoney($estimatedTotal),
            ]);

            return $stockUsage->refresh()->load(['creator', 'completer', 'items.medicine', 'items.batch']);
        });
    }

    public function cancelCompleted(StockUsage $stockUsage, User $user, string $reason): StockUsage
    {
        if ($this->statusValue($stockUsage->status) !== StockUsageStatus::Completed->value) {
            $this->fail('status', 'Hanya stock usage completed yang dapat dibatalkan.');
        }

        return DB::transaction(function () use ($stockUsage, $user, $reason): StockUsage {
            $stockUsage = StockUsage::query()
                ->with(['items.batch.medicine'])
                ->whereKey($stockUsage->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->statusValue($stockUsage->status) !== StockUsageStatus::Completed->value) {
                $this->fail('status', 'Hanya stock usage completed yang dapat dibatalkan.');
            }

            foreach ($stockUsage->items as $item) {
                $this->stockService->cancelUsage($item->batch, $item->quantity, $user, [
                    'reference_id' => $stockUsage->id,
                    'unit_cost_snapshot' => $item->cost_snapshot,
                    'description' => "{$stockUsage->code} dibatalkan: {$reason}",
                ]);
            }

            $stockUsage->update([
                'status' => StockUsageStatus::Cancelled,
                'reason' => trim($stockUsage->reason."\nPembatalan: ".$reason),
            ]);

            return $stockUsage->refresh()->load(['creator', 'completer', 'items.medicine', 'items.batch']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(StockUsage $stockUsage, array $items): float
    {
        $estimatedTotal = 0.0;

        foreach (array_values($items) as $index => $item) {
            $batch = MedicineBatch::query()
                ->with('medicine')
                ->findOrFail($item['medicine_batch_id']);

            $this->validateItem($batch, $item['quantity'] ?? 0, "items.{$index}.quantity");

            $quantity = $this->quantity($item['quantity']);
            $cost = $this->money($batch->purchase_price);
            $estimatedCost = $this->money($quantity * $cost);
            $estimatedTotal = $this->money($estimatedTotal + $estimatedCost);

            StockUsageItem::query()->create([
                'stock_usage_id' => $stockUsage->id,
                'medicine_id' => $batch->medicine_id,
                'medicine_batch_id' => $batch->id,
                'quantity' => $this->formatQuantity($quantity),
                'cost_snapshot' => $this->formatMoney($cost),
                'estimated_cost' => $this->formatMoney($estimatedCost),
                'notes' => $item['notes'] ?? null,
            ]);
        }

        return $estimatedTotal;
    }

    private function validateItem(MedicineBatch $batch, float|int|string $quantity, string $key): void
    {
        $quantity = $this->quantity($quantity);

        if ($quantity <= 0) {
            $this->fail($key, 'Quantity harus lebih dari 0.');
        }

        if ($this->quantity($batch->current_stock) < $quantity) {
            $this->fail($key, 'Stok batch tidak cukup untuk stock usage.');
        }
    }

    private function ensureDraft(StockUsage $stockUsage): void
    {
        if ($this->statusValue($stockUsage->status) !== StockUsageStatus::Draft->value) {
            $this->fail('status', 'Stock usage completed atau cancelled tidak dapat diubah.');
        }
    }

    private function generateCode(string $date): string
    {
        $prefix = 'USE-'.Carbon::parse($date)->format('Ymd').'-';
        $lastCode = StockUsage::query()
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');
        $nextNumber = $lastCode ? ((int) substr($lastCode, -4)) + 1 : 1;

        return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function statusValue(StockUsageStatus|string $status): string
    {
        return $status instanceof StockUsageStatus ? $status->value : $status;
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
