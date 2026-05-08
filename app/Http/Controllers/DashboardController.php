<?php

namespace App\Http\Controllers;

use App\Enums\MedicineBatchStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SaleStatus;
use App\Enums\StockAdjustmentStatus;
use App\Models\ActivityLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\StockUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->role?->value ?? $user->role;

        return Inertia::render('Dashboard', [
            'dashboard' => match ($role) {
                'super_admin' => $this->superAdminDashboard(),
                'admin' => $this->adminDashboard(),
                default => $this->employeeDashboard($user->id),
            },
        ]);
    }

    private function superAdminDashboard(): array
    {
        $stock = $this->stockSummary();
        $today = $this->today();

        return [
            'role' => 'super_admin',
            'generated_at' => Carbon::now(config('app.timezone'))->toDateTimeString(),
            'cards' => [
                $this->card('total_active_medicines', 'Obat aktif', $stock['active_medicines']),
                $this->card('low_stock', 'Stok menipis', $stock['low_stock']),
                $this->card('out_of_stock', 'Stok habis', $stock['out_of_stock']),
                $this->card('near_expiry', 'Hampir kedaluwarsa', $stock['near_expiry_count']),
                $this->card('expired_batches', 'Batch kedaluwarsa', $stock['expired_count']),
                $this->card('sales_today', 'Penjualan hari ini', $this->salesToday()['count']),
                $this->card('purchases_this_month', 'Pembelian bulan ini', $this->purchasesThisMonth()['count']),
                $this->card('inventory_value', 'Nilai persediaan', $this->inventoryValue(), 'currency'),
            ],
            'sections' => [
                'latest_activity' => $this->latestActivity(),
                'near_expiry_batches' => $this->nearExpiryBatches(),
                'sales_today' => $this->salesToday(),
                'purchases_this_month' => $this->purchasesThisMonth(),
            ],
            'period' => [
                'today' => $today->toDateString(),
                'month' => $today->format('Y-m'),
            ],
        ];
    }

    private function adminDashboard(): array
    {
        $stock = $this->stockSummary();
        $today = $this->today();

        return [
            'role' => 'admin',
            'generated_at' => Carbon::now(config('app.timezone'))->toDateTimeString(),
            'cards' => [
                $this->card('total_active_medicines', 'Obat aktif', $stock['active_medicines']),
                $this->card('low_stock', 'Stok menipis', $stock['low_stock']),
                $this->card('out_of_stock', 'Stok habis', $stock['out_of_stock']),
                $this->card('near_expiry', 'Hampir kedaluwarsa', $stock['near_expiry_count']),
            ],
            'sections' => [
                'stock_summary' => $stock,
                'latest_purchase_orders' => $this->latestPurchaseOrders(),
                'latest_stock_usages' => $this->latestStockUsages(),
                'draft_adjustments' => $this->draftAdjustments(),
                'near_expiry_batches' => $this->nearExpiryBatches(),
            ],
            'period' => [
                'today' => $today->toDateString(),
            ],
        ];
    }

    private function employeeDashboard(int $userId): array
    {
        $stock = $this->stockSummary();

        return [
            'role' => 'employee',
            'generated_at' => Carbon::now(config('app.timezone'))->toDateTimeString(),
            'cards' => [
                $this->card('low_stock', 'Stok penting', $stock['low_stock'] + $stock['out_of_stock']),
                $this->card('sales_today_by_user', 'Penjualan saya hari ini', $this->salesToday($userId)['count']),
                $this->card('near_expiry', 'Hampir kedaluwarsa', $stock['near_expiry_count']),
            ],
            'sections' => [
                'important_stock' => $this->importantStock(),
                'sales_today' => $this->salesToday($userId),
                'near_expiry_batches' => $this->nearExpiryBatches(),
            ],
            'period' => [
                'today' => $this->today()->toDateString(),
            ],
        ];
    }

    private function stockSummary(): array
    {
        return [
            'active_medicines' => Medicine::query()->where('is_active', true)->count(),
            'low_stock' => $this->medicineStockQuery()
                ->havingRaw('saleable_stock > 0 and saleable_stock <= medicines.minimum_stock')
                ->get()
                ->count(),
            'out_of_stock' => $this->medicineStockQuery()
                ->havingRaw('saleable_stock = 0')
                ->get()
                ->count(),
            'near_expiry_count' => $this->nearExpiryBatchQuery()->count(),
            'expired_count' => $this->expiredBatchQuery()->count(),
        ];
    }

    private function importantStock(int $limit = 8): array
    {
        return $this->medicineStockQuery()
            ->havingRaw('saleable_stock <= medicines.minimum_stock')
            ->orderByRaw('saleable_stock = 0 desc')
            ->orderBy('saleable_stock')
            ->limit($limit)
            ->get()
            ->map(fn (Medicine $medicine): array => [
                'id' => $medicine->id,
                'name' => $medicine->name,
                'code' => $medicine->code,
                'saleable_stock' => $this->formatQuantity($medicine->saleable_stock),
                'minimum_stock' => $this->formatQuantity($medicine->minimum_stock),
                'status' => (float) $medicine->saleable_stock <= 0 ? 'out_of_stock' : 'low_stock',
            ])
            ->all();
    }

    private function nearExpiryBatches(int $limit = 8): array
    {
        return $this->nearExpiryBatchQuery()
            ->with(['medicine:id,name,code', 'supplier:id,name'])
            ->orderBy('expiry_date')
            ->limit($limit)
            ->get()
            ->map(fn (MedicineBatch $batch): array => [
                'id' => $batch->id,
                'medicine' => $batch->medicine->name,
                'code' => $batch->medicine->code,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'current_stock' => $this->formatQuantity($batch->current_stock),
                'supplier' => $batch->supplier?->name,
            ])
            ->all();
    }

    private function latestActivity(int $limit = 8): array
    {
        return ActivityLog::query()
            ->with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'module' => $log->module,
                'description' => $log->description,
                'user' => $log->user?->name ?? 'Sistem',
                'created_at' => $log->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    private function latestPurchaseOrders(int $limit = 6): array
    {
        return PurchaseOrder::query()
            ->with('supplier:id,name')
            ->latest('order_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PurchaseOrder $purchaseOrder): array => [
                'id' => $purchaseOrder->id,
                'code' => $purchaseOrder->code,
                'supplier' => $purchaseOrder->supplier->name,
                'status' => $purchaseOrder->status?->value ?? $purchaseOrder->status,
                'order_date' => $purchaseOrder->order_date?->toDateString(),
                'total_amount' => $this->formatMoney($purchaseOrder->total_amount),
            ])
            ->all();
    }

    private function latestStockUsages(int $limit = 6): array
    {
        return StockUsage::query()
            ->with('creator:id,name')
            ->latest('usage_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (StockUsage $stockUsage): array => [
                'id' => $stockUsage->id,
                'code' => $stockUsage->code,
                'creator' => $stockUsage->creator->name,
                'usage_type' => $stockUsage->usage_type?->value ?? $stockUsage->usage_type,
                'status' => $stockUsage->status?->value ?? $stockUsage->status,
                'usage_date' => $stockUsage->usage_date?->toDateString(),
                'estimated_total_cost' => $this->formatMoney($stockUsage->estimated_total_cost),
            ])
            ->all();
    }

    private function draftAdjustments(int $limit = 6): array
    {
        return StockAdjustment::query()
            ->with('creator:id,name')
            ->where('status', StockAdjustmentStatus::Draft->value)
            ->latest('adjustment_date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (StockAdjustment $adjustment): array => [
                'id' => $adjustment->id,
                'code' => $adjustment->code,
                'creator' => $adjustment->creator->name,
                'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
                'reason' => $adjustment->reason,
            ])
            ->all();
    }

    private function salesToday(?int $cashierId = null): array
    {
        $query = Sale::query()
            ->where('status', SaleStatus::Completed->value)
            ->whereDate('sale_date', $this->today()->toDateString());

        if ($cashierId) {
            $query->where('cashier_id', $cashierId);
        }

        return [
            'count' => (clone $query)->count(),
            'total_amount' => $this->formatMoney((clone $query)->sum('total_amount')),
            'gross_margin' => $this->formatMoney((clone $query)->sum('gross_margin')),
        ];
    }

    private function purchasesThisMonth(): array
    {
        $today = $this->today();
        $query = PurchaseOrder::query()
            ->where('status', PurchaseOrderStatus::Received->value)
            ->whereBetween('received_date', [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ]);

        return [
            'count' => (clone $query)->count(),
            'total_amount' => $this->formatMoney((clone $query)->sum('total_amount')),
        ];
    }

    private function inventoryValue(): string
    {
        $today = $this->today()->toDateString();

        return $this->formatMoney(MedicineBatch::query()
            ->where('status', MedicineBatchStatus::Available->value)
            ->where('current_stock', '>', 0)
            ->where(fn (Builder $query) => $query
                ->whereNull('expiry_date')
                ->orWhere('expiry_date', '>', $today))
            ->sum(DB::raw('current_stock * purchase_price')));
    }

    private function nearExpiryBatchQuery(): Builder
    {
        $today = $this->today();

        return MedicineBatch::query()
            ->where('status', MedicineBatchStatus::Available->value)
            ->where('current_stock', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>', $today->toDateString())
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($this->expiryWarningDays())->toDateString());
    }

    private function expiredBatchQuery(): Builder
    {
        return MedicineBatch::query()
            ->where('current_stock', '>', 0)
            ->where('status', '!=', MedicineBatchStatus::Quarantined->value)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $this->today()->toDateString());
    }

    private function medicineStockQuery(): Builder
    {
        $today = $this->today()->toDateString();

        return Medicine::query()
            ->select('medicines.*')
            ->selectRaw('coalesce(sum(medicine_batches.current_stock), 0) as saleable_stock')
            ->leftJoin('medicine_batches', function ($join) use ($today): void {
                $join->on('medicine_batches.medicine_id', '=', 'medicines.id')
                    ->where('medicine_batches.status', '=', MedicineBatchStatus::Available->value)
                    ->where('medicine_batches.current_stock', '>', 0)
                    ->where(function ($query) use ($today): void {
                        $query->where('medicine_batches.expiry_date', '>', $today)
                            ->orWhere(function ($subQuery): void {
                                $subQuery
                                    ->whereNull('medicine_batches.expiry_date')
                                    ->whereIn('medicines.classification', ['alkes', 'other']);
                            });
                    });
            })
            ->where('medicines.is_active', true)
            ->groupBy('medicines.id');
    }

    private function card(string $key, string $label, int|float|string $value, string $format = 'number'): array
    {
        return compact('key', 'label', 'value', 'format');
    }

    private function expiryWarningDays(): int
    {
        return (int) (Setting::query()->where('key', 'expiry_warning_days')->value('value') ?: 60);
    }

    private function today(): Carbon
    {
        return Carbon::today(config('app.timezone'));
    }

    private function formatQuantity(int|float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 3, '.', '');
    }

    private function formatMoney(int|float|string|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
