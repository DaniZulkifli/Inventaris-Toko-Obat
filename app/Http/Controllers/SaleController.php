<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Sale;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(Request $request, StockService $stockService): Response
    {
        return $this->renderIndex($request, $stockService, onlyMine: false);
    }

    public function myHistory(Request $request, StockService $stockService): Response
    {
        return $this->renderIndex($request, $stockService, onlyMine: true);
    }

    public function store(Request $request, SaleService $saleService, ActivityLogService $activityLog): RedirectResponse
    {
        $sale = $saleService->complete($this->validatedData($request), $request->user());

        $activityLog->record('complete_sale', 'sales', "Menyelesaikan {$sale->invoice_number}", $request->user(), [
            'sale_id' => $sale->id,
            'total_amount' => $sale->total_amount,
            'payment_method' => $sale->payment_method?->value ?? $sale->payment_method,
        ]);

        return back()->with('success', "Penjualan {$sale->invoice_number} berhasil disimpan.");
    }

    private function renderIndex(Request $request, StockService $stockService, bool $onlyMine): Response
    {
        $user = $request->user();
        $role = $user->role?->value ?? $user->role;
        $historyScope = $onlyMine || $role === 'employee' ? 'mine' : 'all';
        $filters = [
            'search' => $request->string('search')->toString(),
            'payment_method' => $request->string('payment_method')->toString(),
            'cashier_id' => $request->string('cashier_id')->toString(),
            'date_from' => $request->string('date_from')->toString(),
            'date_to' => $request->string('date_to')->toString(),
        ];

        $sales = Sale::query()
            ->with(['cashier:id,name', 'items.medicine:id,code,name', 'items.batch:id,batch_number'])
            ->when($historyScope === 'mine', fn ($query) => $query->where('cashier_id', $user->id))
            ->when($historyScope === 'all' && $filters['cashier_id'], fn ($query, string $cashierId) => $query->where('cashier_id', $cashierId))
            ->when($filters['search'], function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhereHas('items', function ($query) use ($search): void {
                            $query
                                ->where('medicine_name_snapshot', 'like', "%{$search}%")
                                ->orWhere('medicine_code_snapshot', 'like', "%{$search}%")
                                ->orWhere('batch_number_snapshot', 'like', "%{$search}%");
                        });
                });
            })
            ->when($filters['payment_method'], fn ($query, string $method) => $query->where('payment_method', $method))
            ->when($filters['date_from'], fn ($query, string $date) => $query->whereDate('sale_date', '>=', $date))
            ->when($filters['date_to'], fn ($query, string $date) => $query->whereDate('sale_date', '<=', $date))
            ->latest('sale_date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Sale $sale): array => $this->saleRow($sale));

        return Inertia::render('Sales/Index', [
            'sales' => $sales,
            'filters' => $filters,
            'historyScope' => $historyScope,
            'canCreate' => $request->routeIs('sales.index'),
            'options' => [
                'medicines' => $this->medicineOptions($stockService),
                'saleable_batches' => $this->saleableBatchOptions($stockService),
                'payment_methods' => [
                    ['value' => PaymentMethod::Cash->value, 'label' => 'Cash'],
                    ['value' => PaymentMethod::Transfer->value, 'label' => 'Transfer'],
                    ['value' => PaymentMethod::Qris->value, 'label' => 'QRIS'],
                    ['value' => PaymentMethod::Other->value, 'label' => 'Other'],
                ],
                'cashiers' => $historyScope === 'all'
                    ? User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $cashier): array => [
                        'value' => $cashier->id,
                        'label' => $cashier->name,
                    ])
                    : [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request): array
    {
        return $request->validate([
            'customer_name' => ['nullable', 'string', 'max:150'],
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'items.*.medicine_batch_id' => ['nullable', 'integer', 'exists:medicine_batches,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function medicineOptions(StockService $stockService)
    {
        return Medicine::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'barcode', 'name', 'classification', 'selling_price'])
            ->map(fn (Medicine $medicine): array => [
                'id' => $medicine->id,
                'code' => $medicine->code,
                'barcode' => $medicine->barcode,
                'name' => $medicine->name,
                'label' => "{$medicine->code} - {$medicine->name}",
                'classification' => $medicine->classification?->value ?? $medicine->classification,
                'selling_price' => $medicine->selling_price,
                'saleable_stock' => $stockService->saleableStock($medicine),
            ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function saleableBatchOptions(StockService $stockService)
    {
        return MedicineBatch::query()
            ->with('medicine:id,code,name,classification,is_active,selling_price')
            ->where('current_stock', '>', 0)
            ->orderBy('medicine_id')
            ->orderByRaw('expiry_date IS NULL')
            ->orderBy('expiry_date')
            ->get()
            ->filter(fn (MedicineBatch $batch): bool => $stockService->isSaleableBatch($batch))
            ->map(fn (MedicineBatch $batch): array => [
                'id' => $batch->id,
                'medicine_id' => $batch->medicine_id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'current_stock' => $batch->current_stock,
                'unit_price' => $stockService->sellingPriceFor($batch),
                'label' => $batch->batch_number.' - stok '.$batch->current_stock.($batch->expiry_date ? ' - exp '.$batch->expiry_date->toDateString() : ''),
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function saleRow(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'invoice_number' => $sale->invoice_number,
            'cashier' => $sale->cashier?->name,
            'cashier_id' => $sale->cashier_id,
            'sale_date' => $sale->sale_date?->toDateTimeString(),
            'customer_name' => $sale->customer_name,
            'payment_method' => $sale->payment_method?->value ?? $sale->payment_method,
            'status' => $sale->status?->value ?? $sale->status,
            'subtotal' => $sale->subtotal,
            'discount' => $sale->discount,
            'total_amount' => $sale->total_amount,
            'amount_paid' => $sale->amount_paid,
            'change_amount' => $sale->change_amount,
            'gross_margin' => $sale->gross_margin,
            'notes' => $sale->notes,
            'items' => $sale->items->map(fn ($item): array => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'medicine_batch_id' => $item->medicine_batch_id,
                'medicine_code_snapshot' => $item->medicine_code_snapshot,
                'medicine_name_snapshot' => $item->medicine_name_snapshot,
                'batch_number_snapshot' => $item->batch_number_snapshot,
                'expiry_date_snapshot' => $item->expiry_date_snapshot?->toDateString(),
                'quantity' => $item->quantity,
                'unit_price_snapshot' => $item->unit_price_snapshot,
                'cost_snapshot' => $item->cost_snapshot,
                'subtotal' => $item->subtotal,
                'gross_margin' => $item->gross_margin,
            ])->values(),
        ];
    }
}
