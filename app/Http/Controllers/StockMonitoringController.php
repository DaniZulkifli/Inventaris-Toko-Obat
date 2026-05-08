<?php

namespace App\Http\Controllers;

use App\Enums\MedicineBatchStatus;
use App\Enums\MedicineClassification;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\Setting;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class StockMonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $today = Carbon::today(config('app.timezone'));
        $expiryWarningDays = $this->expiryWarningDays();
        $warningDate = $today->copy()->addDays($expiryWarningDays);

        $medicines = $this->medicineStockQuery($filters, $today)
            ->paginate(10, ['*'], 'medicines_page')
            ->withQueryString()
            ->through(fn (Medicine $medicine): array => $this->medicineRow($medicine));

        $batches = $this->batchQuery($filters)
            ->paginate(10, ['*'], 'batches_page')
            ->withQueryString()
            ->through(fn (MedicineBatch $batch): array => $this->batchRow($batch, $today, $warningDate));

        return Inertia::render('Stock/Summary', [
            'medicines' => $medicines,
            'batches' => $batches,
            'filters' => $filters,
            'options' => [
                'stock_statuses' => $this->stockStatusOptions(),
                'batch_statuses' => $this->batchStatusOptions(),
                'categories' => $this->categories(),
                'medicines' => $this->medicines(),
                'suppliers' => $this->suppliers(),
                'expiry_warning_days' => $expiryWarningDays,
                'today' => $today->toDateString(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $stockStatus = (string) ($request->input('stock_status') ?? 'all');
        $batchStatus = (string) ($request->input('batch_status') ?? '');

        return [
            'tab' => in_array($request->input('tab', 'medicines'), ['medicines', 'batches'], true) ? $request->input('tab', 'medicines') : 'medicines',
            'search' => trim((string) $request->input('search', '')),
            'stock_status' => in_array($stockStatus, ['all', 'safe', 'low_stock', 'out_of_stock'], true) ? $stockStatus : 'all',
            'batch_status' => in_array($batchStatus, array_column($this->batchStatusOptions(), 'value'), true) ? $batchStatus : '',
            'category_id' => (string) ($request->input('category_id') ?? ''),
            'medicine_id' => (string) ($request->input('medicine_id') ?? ''),
            'supplier_id' => (string) ($request->input('supplier_id') ?? ''),
            'expiry_from' => (string) ($request->input('expiry_from') ?? ''),
            'expiry_to' => (string) ($request->input('expiry_to') ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function medicineStockQuery(array $filters, Carbon $today): Builder
    {
        $saleableStockExpression = 'COALESCE(SUM(saleable_batches.current_stock), 0)';

        return Medicine::query()
            ->with(['category:id,name', 'unit:id,name,symbol'])
            ->leftJoin('medicine_batches as saleable_batches', function (JoinClause $join) use ($filters, $today): void {
                $join
                    ->on('saleable_batches.medicine_id', '=', 'medicines.id')
                    ->where('saleable_batches.status', MedicineBatchStatus::Available->value)
                    ->where('saleable_batches.current_stock', '>', 0)
                    ->where(function ($query) use ($today): void {
                        $query
                            ->whereDate('saleable_batches.expiry_date', '>', $today->toDateString())
                            ->orWhere(function ($query): void {
                                $query
                                    ->whereNull('saleable_batches.expiry_date')
                                    ->whereIn('medicines.classification', [
                                        MedicineClassification::Alkes->value,
                                        MedicineClassification::Other->value,
                                    ]);
                            });
                    });

                if ($filters['supplier_id'] !== '') {
                    $join->where('saleable_batches.supplier_id', (int) $filters['supplier_id']);
                }
            })
            ->select('medicines.*')
            ->selectRaw("{$saleableStockExpression} as saleable_stock")
            ->where('medicines.is_active', true)
            ->when($filters['category_id'] !== '', fn (Builder $query) => $query->where('medicines.medicine_category_id', (int) $filters['category_id']))
            ->when($filters['medicine_id'] !== '', fn (Builder $query) => $query->where('medicines.id', (int) $filters['medicine_id']))
            ->when($filters['supplier_id'] !== '', function (Builder $query) use ($filters): void {
                $query->whereHas('batches', fn (Builder $batchQuery) => $batchQuery->where('supplier_id', (int) $filters['supplier_id']));
            })
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $searchQuery) use ($filters): void {
                    $search = '%'.$filters['search'].'%';

                    $searchQuery
                        ->where('medicines.code', 'like', $search)
                        ->orWhere('medicines.name', 'like', $search)
                        ->orWhere('medicines.generic_name', 'like', $search);
                });
            })
            ->groupBy('medicines.id')
            ->when($filters['stock_status'] === 'safe', fn (Builder $query) => $query->havingRaw("{$saleableStockExpression} > medicines.minimum_stock"))
            ->when($filters['stock_status'] === 'low_stock', fn (Builder $query) => $query->havingRaw("{$saleableStockExpression} > 0 and {$saleableStockExpression} <= medicines.minimum_stock"))
            ->when($filters['stock_status'] === 'out_of_stock', fn (Builder $query) => $query->havingRaw("{$saleableStockExpression} = 0"))
            ->orderBy('medicines.name');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function batchQuery(array $filters): Builder
    {
        return MedicineBatch::query()
            ->with(['medicine.category:id,name', 'medicine.unit:id,name,symbol', 'supplier:id,name'])
            ->whereHas('medicine', function (Builder $query) use ($filters): void {
                $query
                    ->where('is_active', true)
                    ->when($filters['category_id'] !== '', fn (Builder $categoryQuery) => $categoryQuery->where('medicine_category_id', (int) $filters['category_id']));
            })
            ->when($filters['medicine_id'] !== '', fn (Builder $query) => $query->where('medicine_id', (int) $filters['medicine_id']))
            ->when($filters['supplier_id'] !== '', fn (Builder $query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when($filters['batch_status'] !== '', fn (Builder $query) => $query->where('status', $filters['batch_status']))
            ->when($filters['expiry_from'] !== '', fn (Builder $query) => $query->whereNotNull('expiry_date')->whereDate('expiry_date', '>=', $filters['expiry_from']))
            ->when($filters['expiry_to'] !== '', fn (Builder $query) => $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $filters['expiry_to']))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $searchQuery) use ($filters): void {
                    $search = '%'.$filters['search'].'%';

                    $searchQuery
                        ->where('batch_number', 'like', $search)
                        ->orWhereHas('medicine', function (Builder $medicineQuery) use ($search): void {
                            $medicineQuery
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search);
                        });
                });
            })
            ->orderByRaw('expiry_date is null')
            ->orderBy('expiry_date')
            ->orderBy('batch_number');
    }

    /**
     * @return array<string, mixed>
     */
    private function medicineRow(Medicine $medicine): array
    {
        $saleableStock = (float) $medicine->saleable_stock;
        $minimumStock = (float) $medicine->minimum_stock;
        $stockStatus = match (true) {
            $saleableStock <= 0 => 'out_of_stock',
            $saleableStock <= $minimumStock => 'low_stock',
            default => 'safe',
        };

        return [
            'id' => $medicine->id,
            'code' => $medicine->code,
            'name' => $medicine->name,
            'category' => $medicine->category?->name,
            'unit' => $medicine->unit?->symbol ?? $medicine->unit?->name,
            'classification' => $medicine->classification?->value,
            'saleable_stock' => $saleableStock,
            'minimum_stock' => $minimumStock,
            'reorder_level' => (float) $medicine->reorder_level,
            'status' => $stockStatus,
            'status_label' => $this->stockStatusLabel($stockStatus),
            'selling_price' => (float) $medicine->selling_price,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function batchRow(MedicineBatch $batch, Carbon $today, Carbon $warningDate): array
    {
        $expiryState = $this->expiryState($batch, $today, $warningDate);

        return [
            'id' => $batch->id,
            'medicine_id' => $batch->medicine_id,
            'medicine_code' => $batch->medicine?->code,
            'medicine' => $batch->medicine?->name,
            'category' => $batch->medicine?->category?->name,
            'unit' => $batch->medicine?->unit?->symbol ?? $batch->medicine?->unit?->name,
            'supplier' => $batch->supplier?->name,
            'batch_number' => $batch->batch_number,
            'expiry_date' => $batch->expiry_date?->toDateString(),
            'received_date' => $batch->received_date?->toDateString(),
            'initial_stock' => (float) $batch->initial_stock,
            'current_stock' => (float) $batch->current_stock,
            'purchase_price' => (float) $batch->purchase_price,
            'status' => $batch->status?->value,
            'status_label' => $this->batchStatusLabel($batch->status?->value),
            'expiry_state' => $expiryState,
            'expiry_label' => $this->expiryStateLabel($expiryState),
            'notes' => $batch->notes,
        ];
    }

    private function expiryState(MedicineBatch $batch, Carbon $today, Carbon $warningDate): string
    {
        if (! $batch->expiry_date) {
            return 'no_expiry';
        }

        if ($batch->expiry_date->lte($today)) {
            return 'expired';
        }

        if ($batch->expiry_date->gt($today) && $batch->expiry_date->lte($warningDate)) {
            return 'near_expiry';
        }

        return 'normal';
    }

    private function expiryWarningDays(): int
    {
        return (int) (Setting::query()->where('key', 'expiry_warning_days')->value('value') ?? 60);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function stockStatusOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'Semua stok'],
            ['value' => 'safe', 'label' => 'Aman'],
            ['value' => 'low_stock', 'label' => 'Menipis'],
            ['value' => 'out_of_stock', 'label' => 'Habis'],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function batchStatusOptions(): array
    {
        return collect(MedicineBatchStatus::cases())
            ->map(fn (MedicineBatchStatus $status): array => [
                'value' => $status->value,
                'label' => $this->batchStatusLabel($status->value),
            ])
            ->all();
    }

    private function stockStatusLabel(string $status): string
    {
        return [
            'safe' => 'Aman',
            'low_stock' => 'Menipis',
            'out_of_stock' => 'Habis',
        ][$status] ?? $status;
    }

    private function batchStatusLabel(?string $status): string
    {
        return [
            'available' => 'Tersedia',
            'expired' => 'Kedaluwarsa',
            'depleted' => 'Habis',
            'quarantined' => 'Karantina',
        ][$status] ?? '-';
    }

    private function expiryStateLabel(string $state): string
    {
        return [
            'normal' => 'Normal',
            'near_expiry' => 'Hampir kedaluwarsa',
            'expired' => 'Kedaluwarsa',
            'no_expiry' => 'Tanpa expiry',
        ][$state] ?? $state;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categories(): array
    {
        return MedicineCategory::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (MedicineCategory $category): array => [
                'value' => $category->id,
                'label' => $category->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function medicines(): array
    {
        return Medicine::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Medicine $medicine): array => [
                'value' => $medicine->id,
                'label' => "{$medicine->code} - {$medicine->name}",
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function suppliers(): array
    {
        return Supplier::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier): array => [
                'value' => $supplier->id,
                'label' => $supplier->name,
            ])
            ->all();
    }
}
