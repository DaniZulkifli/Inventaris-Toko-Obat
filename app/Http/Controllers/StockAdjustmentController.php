<?php

namespace App\Http\Controllers;

use App\Enums\StockAdjustmentStatus;
use App\Models\MedicineBatch;
use App\Models\StockAdjustment;
use App\Services\ActivityLogService;
use App\Services\StockAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockAdjustmentController extends Controller
{
    public function index(Request $request): Response
    {
        $role = $request->user()->role?->value ?? $request->user()->role;
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
            'date_from' => $request->string('date_from')->toString(),
            'date_to' => $request->string('date_to')->toString(),
        ];

        $adjustments = StockAdjustment::query()
            ->with(['creator:id,name', 'approver:id,name', 'items.medicine:id,code,name', 'items.batch:id,batch_number,current_stock,status'])
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
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'], fn ($query, string $date) => $query->whereDate('adjustment_date', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query->whereDate('adjustment_date', '<=', $date))
            ->latest('adjustment_date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (StockAdjustment $adjustment): array => $this->adjustmentRow($adjustment, $role));

        return Inertia::render('StockAdjustments/Index', [
            'adjustments' => $adjustments,
            'filters' => $filters,
            'canApprove' => $role === 'super_admin',
            'canCancelApproved' => $role === 'super_admin',
            'options' => [
                'statuses' => [
                    ['value' => StockAdjustmentStatus::Draft->value, 'label' => 'Draft'],
                    ['value' => StockAdjustmentStatus::Approved->value, 'label' => 'Disetujui'],
                    ['value' => StockAdjustmentStatus::Cancelled->value, 'label' => 'Dibatalkan'],
                ],
                'batches' => $this->batchOptions(),
            ],
        ]);
    }

    public function store(Request $request, StockAdjustmentService $adjustmentService, ActivityLogService $activityLog): RedirectResponse
    {
        $adjustment = $adjustmentService->createDraft($this->validatedData($request), $request->user());

        $activityLog->record('create', 'stock_adjustments', "Membuat {$adjustment->code}", $request->user(), [
            'stock_adjustment_id' => $adjustment->id,
            'status' => $adjustment->status?->value ?? $adjustment->status,
        ]);

        return back()->with('success', 'Draft penyesuaian stok berhasil dibuat.');
    }

    public function update(
        Request $request,
        StockAdjustment $stockAdjustment,
        StockAdjustmentService $adjustmentService,
        ActivityLogService $activityLog
    ): RedirectResponse {
        $adjustment = $adjustmentService->updateDraft($stockAdjustment, $this->validatedData($request));

        $activityLog->record('update', 'stock_adjustments', "Mengubah {$adjustment->code}", $request->user(), [
            'stock_adjustment_id' => $adjustment->id,
        ]);

        return back()->with('success', 'Draft penyesuaian stok berhasil diubah.');
    }

    public function approve(
        Request $request,
        StockAdjustment $stockAdjustment,
        StockAdjustmentService $adjustmentService,
        ActivityLogService $activityLog
    ): RedirectResponse {
        $adjustment = $adjustmentService->approve($stockAdjustment, $request->user());

        $activityLog->record('approve_adjustment', 'stock_adjustments', "Menyetujui {$adjustment->code}", $request->user(), [
            'stock_adjustment_id' => $adjustment->id,
        ]);

        return back()->with('success', 'Penyesuaian stok berhasil disetujui dan stok sudah diperbarui.');
    }

    public function cancel(
        Request $request,
        StockAdjustment $stockAdjustment,
        StockAdjustmentService $adjustmentService,
        ActivityLogService $activityLog
    ): RedirectResponse {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:1000'],
        ]);

        $adjustment = $adjustmentService->cancelApproved($stockAdjustment, $request->user(), $validated['cancel_reason']);

        $activityLog->record('cancel_adjustment', 'stock_adjustments', "Membatalkan {$adjustment->code}", $request->user(), [
            'stock_adjustment_id' => $adjustment->id,
            'reason' => $validated['cancel_reason'],
        ]);

        return back()->with('success', 'Penyesuaian stok yang disetujui berhasil dibatalkan.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'adjustment_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_batch_id' => ['required', 'integer', 'exists:medicine_batches,id'],
            'items.*.counted_stock' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function batchOptions()
    {
        return MedicineBatch::query()
            ->with('medicine:id,code,name')
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
    private function adjustmentRow(StockAdjustment $adjustment, string $role): array
    {
        $status = $adjustment->status?->value ?? $adjustment->status;

        return [
            'id' => $adjustment->id,
            'code' => $adjustment->code,
            'created_by' => $adjustment->creator?->name,
            'approved_by' => $adjustment->approver?->name,
            'adjustment_date' => $adjustment->adjustment_date?->toDateString(),
            'status' => $status,
            'reason' => $adjustment->reason,
            'items_count' => $adjustment->items_count,
            'can_edit' => $status === StockAdjustmentStatus::Draft->value,
            'can_approve' => $role === 'super_admin' && $status === StockAdjustmentStatus::Draft->value,
            'can_cancel' => $role === 'super_admin' && $status === StockAdjustmentStatus::Approved->value,
            'items' => $adjustment->items->map(fn ($item): array => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'medicine_batch_id' => $item->medicine_batch_id,
                'medicine' => $item->medicine?->name,
                'medicine_code' => $item->medicine?->code,
                'batch_number' => $item->batch?->batch_number,
                'batch_status' => $item->batch?->status?->value ?? $item->batch?->status,
                'current_stock' => $item->batch?->current_stock,
                'system_stock' => $item->system_stock,
                'counted_stock' => $item->counted_stock,
                'difference' => $item->difference,
                'cost_snapshot' => $item->cost_snapshot,
                'notes' => $item->notes,
            ])->values(),
        ];
    }
}
