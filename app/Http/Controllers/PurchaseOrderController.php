<?php

namespace App\Http\Controllers;

use App\Enums\MedicineClassification;
use App\Enums\PurchaseOrderStatus;
use App\Models\Medicine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\ActivityLogService;
use App\Services\PurchaseOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'supplier_id' => $request->string('supplier_id')->toString(),
            'status' => $request->string('status')->toString(),
            'date_from' => $request->string('date_from')->toString(),
            'date_to' => $request->string('date_to')->toString(),
        ];

        $purchaseOrders = PurchaseOrder::query()
            ->with(['supplier:id,name,is_active', 'creator:id,name', 'receiver:id,name', 'items.medicine:id,code,name,classification'])
            ->withCount('items')
            ->when($filters['search'], function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('code', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['supplier_id'], fn ($query, string $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date_from'], fn ($query, string $date) => $query->whereDate('order_date', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query->whereDate('order_date', '<=', $date))
            ->latest('order_date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (PurchaseOrder $purchaseOrder): array => $this->purchaseOrderRow($purchaseOrder));

        return Inertia::render('PurchaseOrders/Index', [
            'purchaseOrders' => $purchaseOrders,
            'filters' => $filters,
            'options' => [
                'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'is_active']),
                'active_suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'medicines' => Medicine::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'code', 'name', 'classification', 'default_purchase_price'])
                    ->map(fn (Medicine $medicine): array => [
                        'id' => $medicine->id,
                        'label' => "{$medicine->code} - {$medicine->name}",
                        'classification' => $medicine->classification?->value ?? $medicine->classification,
                        'default_purchase_price' => $medicine->default_purchase_price,
                    ]),
                'statuses' => [
                    ['value' => PurchaseOrderStatus::Draft->value, 'label' => 'Draft'],
                    ['value' => PurchaseOrderStatus::Received->value, 'label' => 'Diterima'],
                ],
                'no_expiry_classifications' => [
                    MedicineClassification::Alkes->value,
                    MedicineClassification::Other->value,
                ],
            ],
        ]);
    }

    public function store(Request $request, PurchaseOrderService $purchaseOrderService, ActivityLogService $activityLog): RedirectResponse
    {
        $purchaseOrder = $purchaseOrderService->createDraft($this->validatedData($request), $request->user());

        $activityLog->record('create', 'purchase_orders', "Membuat {$purchaseOrder->code}", $request->user(), [
            'purchase_order_id' => $purchaseOrder->id,
            'total_amount' => $purchaseOrder->total_amount,
        ]);

        return back()->with('success', 'Draft pesanan pembelian berhasil dibuat.');
    }

    public function update(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrderService,
        ActivityLogService $activityLog
    ): RedirectResponse {
        $purchaseOrder = $purchaseOrderService->updateDraft($purchaseOrder, $this->validatedData($request));

        $activityLog->record('update', 'purchase_orders', "Mengubah {$purchaseOrder->code}", $request->user(), [
            'purchase_order_id' => $purchaseOrder->id,
            'total_amount' => $purchaseOrder->total_amount,
        ]);

        return back()->with('success', 'Draft pesanan pembelian berhasil diubah.');
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderService $purchaseOrderService, ActivityLogService $activityLog): RedirectResponse
    {
        $code = $purchaseOrder->code;
        $purchaseOrderService->deleteDraft($purchaseOrder);

        $activityLog->record('delete', 'purchase_orders', "Menghapus {$code}", $request->user(), [
            'purchase_order_id' => $purchaseOrder->id,
        ]);

        return back()->with('success', 'Draft pesanan pembelian berhasil dihapus.');
    }

    public function receive(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderService $purchaseOrderService,
        ActivityLogService $activityLog
    ): RedirectResponse {
        $purchaseOrder = $purchaseOrderService->receive($purchaseOrder, $request->user());

        $activityLog->record('receive_purchase', 'purchase_orders', "Menerima {$purchaseOrder->code}", $request->user(), [
            'purchase_order_id' => $purchaseOrder->id,
            'total_amount' => $purchaseOrder->total_amount,
        ]);

        return back()->with('success', 'Pesanan pembelian berhasil diterima dan stok sudah diperbarui.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseOrderRow(PurchaseOrder $purchaseOrder): array
    {
        return [
            'id' => $purchaseOrder->id,
            'code' => $purchaseOrder->code,
            'supplier_id' => $purchaseOrder->supplier_id,
            'supplier' => $purchaseOrder->supplier->name,
            'supplier_is_active' => $purchaseOrder->supplier->is_active,
            'created_by' => $purchaseOrder->creator?->name,
            'received_by' => $purchaseOrder->receiver?->name,
            'order_date' => $purchaseOrder->order_date?->toDateString(),
            'received_date' => $purchaseOrder->received_date?->toDateString(),
            'status' => $purchaseOrder->status?->value ?? $purchaseOrder->status,
            'subtotal' => $purchaseOrder->subtotal,
            'discount' => $purchaseOrder->discount,
            'total_amount' => $purchaseOrder->total_amount,
            'notes' => $purchaseOrder->notes,
            'items_count' => $purchaseOrder->items_count,
            'can_edit' => ($purchaseOrder->status?->value ?? $purchaseOrder->status) === PurchaseOrderStatus::Draft->value,
            'can_receive' => ($purchaseOrder->status?->value ?? $purchaseOrder->status) === PurchaseOrderStatus::Draft->value,
            'can_delete' => ($purchaseOrder->status?->value ?? $purchaseOrder->status) === PurchaseOrderStatus::Draft->value,
            'items' => $purchaseOrder->items->map(fn ($item): array => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'medicine_batch_id' => $item->medicine_batch_id,
                'medicine' => $item->medicine?->name,
                'medicine_code' => $item->medicine?->code,
                'medicine_label' => $item->medicine ? "{$item->medicine->code} - {$item->medicine->name}" : '-',
                'classification' => $item->medicine?->classification?->value ?? $item->medicine?->classification,
                'batch_number' => $item->batch_number,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'quantity' => $item->quantity,
                'unit_cost' => $item->unit_cost,
                'subtotal' => $item->subtotal,
            ])->values(),
        ];
    }
}
