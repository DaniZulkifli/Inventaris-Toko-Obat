<?php

namespace App\Http\Controllers;

use App\Enums\StockUsageStatus;
use App\Enums\StockUsageType;
use App\Models\MedicineBatch;
use App\Models\StockUsage;
use App\Services\ActivityLogService;
use App\Services\StockUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StockUsageController extends Controller
{
    public function index(Request $request): Response
    {
        $role = $request->user()->role?->value ?? $request->user()->role;
        $filters = [
            'search' => $request->string('search')->toString(),
            'usage_type' => $request->string('usage_type')->toString(),
            'status' => $request->string('status')->toString(),
            'date_from' => $request->string('date_from')->toString(),
            'date_to' => $request->string('date_to')->toString(),
        ];

        $stockUsages = StockUsage::query()
            ->with(['creator:id,name', 'completer:id,name', 'items.medicine:id,code,name', 'items.batch:id,batch_number,current_stock,status'])
            ->withCount('items')
            ->when($filters['search'], function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($query) use ($search): void {
                            $query
                                ->whereHas('medicine', fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                                ->orWhereHas('batch', fn ($query) => $query->where('batch_number', 'like', "%{$search}%"));
                        });
                });
            })
            ->when($filters['usage_type'], fn ($query, string $type) => $query->where('usage_type', $type))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'], fn ($query, string $date) => $query->whereDate('usage_date', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query->whereDate('usage_date', '<=', $date))
            ->latest('usage_date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (StockUsage $stockUsage): array => $this->stockUsageRow($stockUsage, $role));

        return Inertia::render('StockUsages/Index', [
            'stockUsages' => $stockUsages,
            'filters' => $filters,
            'canCancelCompleted' => $role === 'super_admin',
            'options' => [
                'usage_types' => $this->usageTypeOptions(),
                'statuses' => [
                    ['value' => StockUsageStatus::Draft->value, 'label' => 'Draft'],
                    ['value' => StockUsageStatus::Completed->value, 'label' => 'Selesai'],
                    ['value' => StockUsageStatus::Cancelled->value, 'label' => 'Dibatalkan'],
                ],
                'batches' => $this->batchOptions(),
            ],
        ]);
    }

    public function store(Request $request, StockUsageService $stockUsageService, ActivityLogService $activityLog): RedirectResponse
    {
        $stockUsage = $stockUsageService->createDraft($this->validatedData($request), $request->user());

        $activityLog->record('create', 'stock_usages', "Membuat {$stockUsage->code}", $request->user(), [
            'stock_usage_id' => $stockUsage->id,
            'status' => $stockUsage->status?->value ?? $stockUsage->status,
        ]);

        return back()->with('success', 'Draft pemakaian stok berhasil dibuat.');
    }

    public function update(
        Request $request,
        StockUsage $stockUsage,
        StockUsageService $stockUsageService,
        ActivityLogService $activityLog
    ): RedirectResponse {
        $stockUsage = $stockUsageService->updateDraft($stockUsage, $this->validatedData($request));

        $activityLog->record('update', 'stock_usages', "Mengubah {$stockUsage->code}", $request->user(), [
            'stock_usage_id' => $stockUsage->id,
        ]);

        return back()->with('success', 'Draft pemakaian stok berhasil diubah.');
    }

    public function destroy(Request $request, StockUsage $stockUsage, StockUsageService $stockUsageService, ActivityLogService $activityLog): RedirectResponse
    {
        $code = $stockUsage->code;
        $stockUsageService->deleteDraft($stockUsage);

        $activityLog->record('delete', 'stock_usages', "Menghapus {$code}", $request->user(), [
            'stock_usage_id' => $stockUsage->id,
        ]);

        return back()->with('success', 'Draft pemakaian stok berhasil dihapus.');
    }

    public function complete(Request $request, StockUsage $stockUsage, StockUsageService $stockUsageService, ActivityLogService $activityLog): RedirectResponse
    {
        $stockUsage = $stockUsageService->complete($stockUsage, $request->user());

        $activityLog->record('complete_usage', 'stock_usages', "Menyelesaikan {$stockUsage->code}", $request->user(), [
            'stock_usage_id' => $stockUsage->id,
            'estimated_total_cost' => $stockUsage->estimated_total_cost,
        ]);

        return back()->with('success', 'Pemakaian stok selesai dan stok sudah dikurangi.');
    }

    public function cancel(Request $request, StockUsage $stockUsage, StockUsageService $stockUsageService, ActivityLogService $activityLog): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:1000'],
        ]);

        $stockUsage = $stockUsageService->cancelCompleted($stockUsage, $request->user(), $validated['cancel_reason']);

        $activityLog->record('cancel_usage', 'stock_usages', "Membatalkan {$stockUsage->code}", $request->user(), [
            'stock_usage_id' => $stockUsage->id,
            'reason' => $validated['cancel_reason'],
        ]);

        return back()->with('success', 'Pemakaian stok yang selesai berhasil dibatalkan.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'usage_date' => ['required', 'date'],
            'usage_type' => ['required', Rule::in(array_column(StockUsageType::cases(), 'value'))],
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_batch_id' => ['required', 'integer', 'exists:medicine_batches,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function usageTypeOptions(): array
    {
        return [
            ['value' => StockUsageType::Damaged->value, 'label' => 'Rusak'],
            ['value' => StockUsageType::Expired->value, 'label' => 'Kedaluwarsa'],
            ['value' => StockUsageType::Lost->value, 'label' => 'Hilang'],
            ['value' => StockUsageType::Sample->value, 'label' => 'Sampel'],
            ['value' => StockUsageType::ReturnSupplier->value, 'label' => 'Retur Supplier'],
            ['value' => StockUsageType::InternalUse->value, 'label' => 'Pemakaian Internal'],
            ['value' => StockUsageType::Other->value, 'label' => 'Lainnya'],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function batchOptions()
    {
        return MedicineBatch::query()
            ->with('medicine:id,code,name')
            ->where('current_stock', '>', 0)
            ->orderBy('medicine_id')
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (MedicineBatch $batch): array => [
                'id' => $batch->id,
                'value' => $batch->id,
                'label' => "{$batch->medicine->code} - {$batch->medicine->name} / {$batch->batch_number} / stok {$batch->current_stock}",
                'medicine_id' => $batch->medicine_id,
                'medicine' => $batch->medicine->name,
                'medicine_code' => $batch->medicine->code,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'current_stock' => $batch->current_stock,
                'purchase_price' => $batch->purchase_price,
                'status' => $batch->status?->value ?? $batch->status,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stockUsageRow(StockUsage $stockUsage, string $role): array
    {
        $status = $stockUsage->status?->value ?? $stockUsage->status;

        return [
            'id' => $stockUsage->id,
            'code' => $stockUsage->code,
            'created_by' => $stockUsage->creator?->name,
            'completed_by' => $stockUsage->completer?->name,
            'usage_date' => $stockUsage->usage_date?->toDateString(),
            'usage_type' => $stockUsage->usage_type?->value ?? $stockUsage->usage_type,
            'status' => $status,
            'estimated_total_cost' => $stockUsage->estimated_total_cost,
            'reason' => $stockUsage->reason,
            'items_count' => $stockUsage->items_count,
            'can_edit' => $status === StockUsageStatus::Draft->value,
            'can_complete' => $status === StockUsageStatus::Draft->value,
            'can_delete' => $status === StockUsageStatus::Draft->value,
            'can_cancel' => $role === 'super_admin' && $status === StockUsageStatus::Completed->value,
            'items' => $stockUsage->items->map(fn ($item): array => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'medicine_batch_id' => $item->medicine_batch_id,
                'medicine' => $item->medicine?->name,
                'medicine_code' => $item->medicine?->code,
                'batch_number' => $item->batch?->batch_number,
                'batch_status' => $item->batch?->status?->value ?? $item->batch?->status,
                'current_stock' => $item->batch?->current_stock,
                'quantity' => $item->quantity,
                'cost_snapshot' => $item->cost_snapshot,
                'estimated_cost' => $item->estimated_cost,
                'notes' => $item->notes,
            ])->values(),
        ];
    }
}
