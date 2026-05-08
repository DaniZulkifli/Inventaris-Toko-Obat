<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $movements = $this->movementQuery($filters)
            ->paginate(15)
            ->withQueryString()
            ->through(fn (StockMovement $movement): array => $this->movementRow($movement));

        return Inertia::render('StockMovements/Index', [
            'movements' => $movements,
            'filters' => $filters,
            'options' => [
                'medicines' => $this->medicines(),
                'batches' => $this->batches(),
                'movement_types' => $this->movementTypes(),
                'creators' => $this->creators(),
                'reference_types' => $this->referenceTypes(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $movementType = $request->input('movement_type', '');

        return [
            'search' => trim((string) $request->input('search', '')),
            'date_from' => $request->input('date_from', ''),
            'date_to' => $request->input('date_to', ''),
            'medicine_id' => $request->input('medicine_id', ''),
            'batch_id' => $request->input('batch_id', ''),
            'movement_type' => in_array($movementType, array_column($this->movementTypes(), 'value'), true) ? $movementType : '',
            'created_by' => $request->input('created_by', ''),
            'reference_type' => $request->input('reference_type', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function movementQuery(array $filters): Builder
    {
        return StockMovement::query()
            ->with(['medicine:id,code,name', 'batch:id,batch_number,expiry_date', 'creator:id,name'])
            ->when($filters['date_from'] !== '', fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->when($filters['medicine_id'] !== '', fn (Builder $query) => $query->where('medicine_id', (int) $filters['medicine_id']))
            ->when($filters['batch_id'] !== '', fn (Builder $query) => $query->where('medicine_batch_id', (int) $filters['batch_id']))
            ->when($filters['movement_type'] !== '', fn (Builder $query) => $query->where('movement_type', $filters['movement_type']))
            ->when($filters['created_by'] !== '', fn (Builder $query) => $query->where('created_by', (int) $filters['created_by']))
            ->when($filters['reference_type'] !== '', fn (Builder $query) => $query->where('reference_type', $filters['reference_type']))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $searchQuery) use ($filters): void {
                    $search = '%'.$filters['search'].'%';

                    $searchQuery
                        ->where('reference_type', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhereHas('medicine', function (Builder $medicineQuery) use ($search): void {
                            $medicineQuery
                                ->where('code', 'like', $search)
                                ->orWhere('name', 'like', $search);
                        })
                        ->orWhereHas('batch', fn (Builder $batchQuery) => $batchQuery->where('batch_number', 'like', $search));
                });
            })
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function movementRow(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'medicine_id' => $movement->medicine_id,
            'medicine' => $movement->medicine?->name,
            'medicine_code' => $movement->medicine?->code,
            'medicine_batch_id' => $movement->medicine_batch_id,
            'batch_number' => $movement->batch?->batch_number,
            'batch_expiry_date' => $movement->batch?->expiry_date?->toDateString(),
            'movement_type' => $movement->movement_type?->value,
            'movement_label' => $this->movementTypeLabel($movement->movement_type?->value),
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'reference_label' => $this->referenceLabel($movement),
            'quantity_in' => (float) $movement->quantity_in,
            'quantity_out' => (float) $movement->quantity_out,
            'stock_before' => (float) $movement->stock_before,
            'stock_after' => (float) $movement->stock_after,
            'unit_cost_snapshot' => (float) $movement->unit_cost_snapshot,
            'created_by' => $movement->creator?->name,
            'created_at' => $movement->created_at?->toISOString(),
            'description' => $movement->description,
        ];
    }

    private function referenceLabel(StockMovement $movement): string
    {
        if (! $movement->reference_type) {
            return '-';
        }

        return $movement->reference_type.($movement->reference_id ? ' #'.$movement->reference_id : '');
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
    private function batches(): array
    {
        return MedicineBatch::query()
            ->with('medicine:id,code,name')
            ->orderBy('batch_number')
            ->get(['id', 'medicine_id', 'batch_number'])
            ->map(fn (MedicineBatch $batch): array => [
                'value' => $batch->id,
                'label' => "{$batch->medicine?->code} - {$batch->medicine?->name} / {$batch->batch_number}",
                'medicine_id' => $batch->medicine_id,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function movementTypes(): array
    {
        return collect(MovementType::cases())
            ->map(fn (MovementType $type): array => [
                'value' => $type->value,
                'label' => $this->movementTypeLabel($type->value),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function creators(): array
    {
        $creatorIds = StockMovement::query()
            ->select('created_by')
            ->distinct()
            ->pluck('created_by');

        return User::query()
            ->whereIn('id', $creatorIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function referenceTypes(): array
    {
        return StockMovement::query()
            ->whereNotNull('reference_type')
            ->distinct()
            ->orderBy('reference_type')
            ->pluck('reference_type')
            ->map(fn (string $referenceType): array => [
                'value' => $referenceType,
                'label' => str($referenceType)->replace('_', ' ')->title()->toString(),
            ])
            ->all();
    }

    private function movementTypeLabel(?string $type): string
    {
        return [
            'opening_stock' => 'Opening Stock',
            'purchase_in' => 'Purchase In',
            'sale_out' => 'Sale Out',
            'usage_out' => 'Usage Out',
            'adjustment_in' => 'Adjustment In',
            'adjustment_out' => 'Adjustment Out',
            'cancel_usage' => 'Cancel Usage',
            'cancel_adjustment' => 'Cancel Adjustment',
        ][$type] ?? '-';
    }
}
