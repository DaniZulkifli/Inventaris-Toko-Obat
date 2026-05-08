<?php

namespace App\Services;

use App\Enums\MedicineBatchStatus;
use App\Enums\MedicineClassification;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\PurchaseOrderItem;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use ZipArchive;

class ReportService
{
    private const REPORTS = [
        'stock' => 'Stok',
        'low_stock' => 'Stok Menipis',
        'out_of_stock' => 'Stok Habis',
        'expiry' => 'Kedaluwarsa',
        'purchase' => 'Pembelian',
        'sales' => 'Penjualan',
        'simple_margin' => 'Margin Sederhana',
        'stock_movement' => 'Stock Movement',
        'supplier' => 'Supplier',
    ];

    private const ADMIN_REPORTS = [
        'stock',
        'low_stock',
        'out_of_stock',
        'expiry',
        'purchase',
        'sales',
        'stock_movement',
    ];

    private const DATE_REPORTS = [
        'purchase',
        'sales',
        'simple_margin',
        'stock_movement',
    ];

    /**
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $input, string $role): array
    {
        $availableTypes = array_column($this->reportTypesFor($role), 'value');
        $type = (string) ($input['jenis_laporan'] ?? 'stock');
        $type = in_array($type, $availableTypes, true) ? $type : 'stock';
        $today = $this->today();

        $filters = [
            'jenis_laporan' => $type,
            'date_from' => (string) ($input['date_from'] ?? ''),
            'date_to' => (string) ($input['date_to'] ?? ''),
            'expiry_from' => (string) ($input['expiry_from'] ?? ''),
            'expiry_to' => (string) ($input['expiry_to'] ?? ''),
            'category_id' => (string) ($input['category_id'] ?? ''),
            'medicine_id' => (string) ($input['medicine_id'] ?? ''),
            'supplier_id' => (string) ($input['supplier_id'] ?? ''),
            'batch_status' => (string) ($input['batch_status'] ?? ''),
            'purchase_status' => (string) ($input['purchase_status'] ?? ''),
            'cashier_id' => (string) ($input['cashier_id'] ?? ''),
            'payment_method' => (string) ($input['payment_method'] ?? ''),
            'batch_id' => (string) ($input['batch_id'] ?? ''),
            'movement_type' => (string) ($input['movement_type'] ?? ''),
            'created_by' => (string) ($input['created_by'] ?? ''),
            'supplier_status' => (string) ($input['supplier_status'] ?? ''),
            'supplier_name' => trim((string) ($input['supplier_name'] ?? '')),
        ];

        if (in_array($type, self::DATE_REPORTS, true)) {
            $filters['date_from'] = $this->dateOrDefault($filters['date_from'], $today);
            $filters['date_to'] = $this->dateOrDefault($filters['date_to'], Carbon::parse($filters['date_from'], config('app.timezone')));

            if (Carbon::parse($filters['date_to'])->lt(Carbon::parse($filters['date_from']))) {
                $filters['date_to'] = $filters['date_from'];
            }
        }

        if ($type === 'expiry') {
            $filters['expiry_from'] = $this->dateOrDefault($filters['expiry_from'], $today->copy()->addDay());
            $filters['expiry_to'] = $this->dateOrDefault($filters['expiry_to'], $today->copy()->addDays($this->expiryWarningDays()));

            if (Carbon::parse($filters['expiry_to'])->lt(Carbon::parse($filters['expiry_from']))) {
                $filters['expiry_to'] = $filters['expiry_from'];
            }
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(array $filters, int $perPage = 15): array
    {
        $type = $filters['jenis_laporan'];

        return [
            'type' => $type,
            'title' => self::REPORTS[$type],
            'requires_date' => in_array($type, self::DATE_REPORTS, true),
            'columns' => $this->columns($type),
            'rows' => $this->query($filters)
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn ($row): array => $this->mapRow($type, $row)),
            'summary' => $this->summary($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function export(string $format, array $filters): array
    {
        $payload = $this->exportPayload($filters);
        $filename = $this->filename($filters, $format);

        return [
            'filename' => $filename,
            'mime' => $format === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $format === 'pdf'
                ? $this->buildPdf($payload)
                : $this->buildXlsx($payload),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function reportTypesFor(string $role): array
    {
        $allowed = $role === 'admin' ? self::ADMIN_REPORTS : array_keys(self::REPORTS);

        return collect(self::REPORTS)
            ->filter(fn (string $label, string $value): bool => in_array($value, $allowed, true))
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function options(string $role): array
    {
        return [
            'report_types' => $this->reportTypesFor($role),
            'categories' => $this->categories(),
            'medicines' => $this->medicines(),
            'suppliers' => $this->suppliers(),
            'batches' => $this->batches(),
            'cashiers' => $this->cashiers(),
            'users' => $this->movementCreators(),
            'batch_statuses' => $this->enumOptions(MedicineBatchStatus::cases()),
            'purchase_statuses' => $this->enumOptions(PurchaseOrderStatus::cases()),
            'payment_methods' => $this->enumOptions(PaymentMethod::cases()),
            'movement_types' => $this->enumOptions(MovementType::cases()),
            'supplier_statuses' => [
                ['value' => 'active', 'label' => 'Aktif'],
                ['value' => 'inactive', 'label' => 'Nonaktif'],
            ],
        ];
    }

    private function query(array $filters): Builder
    {
        return match ($filters['jenis_laporan']) {
            'stock' => $this->stockQuery($filters),
            'low_stock' => $this->medicineStockQuery($filters)
                ->havingRaw('saleable_stock > 0 and saleable_stock <= medicines.minimum_stock'),
            'out_of_stock' => $this->medicineStockQuery($filters)
                ->havingRaw('saleable_stock = 0'),
            'expiry' => $this->expiryQuery($filters),
            'purchase' => $this->purchaseQuery($filters),
            'sales' => $this->salesQuery($filters),
            'simple_margin' => $this->simpleMarginQuery($filters),
            'stock_movement' => $this->stockMovementQuery($filters),
            'supplier' => $this->supplierQuery($filters),
            default => $this->stockQuery($filters),
        };
    }

    private function stockQuery(array $filters): Builder
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
            ->orderBy('medicine_id')
            ->orderByRaw('expiry_date is null')
            ->orderBy('expiry_date')
            ->orderBy('batch_number');
    }

    private function medicineStockQuery(array $filters): Builder
    {
        $today = $this->today()->toDateString();

        return Medicine::query()
            ->with(['category:id,name', 'unit:id,name,symbol'])
            ->select('medicines.*')
            ->selectRaw('coalesce(sum(saleable_batches.current_stock), 0) as saleable_stock')
            ->leftJoin('medicine_batches as saleable_batches', function (JoinClause $join) use ($filters, $today): void {
                $join
                    ->on('saleable_batches.medicine_id', '=', 'medicines.id')
                    ->where('saleable_batches.status', MedicineBatchStatus::Available->value)
                    ->where('saleable_batches.current_stock', '>', 0)
                    ->where(function ($query) use ($today): void {
                        $query
                            ->whereDate('saleable_batches.expiry_date', '>', $today)
                            ->orWhere(function ($subQuery): void {
                                $subQuery
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
            ->where('medicines.is_active', true)
            ->when($filters['category_id'] !== '', fn (Builder $query) => $query->where('medicines.medicine_category_id', (int) $filters['category_id']))
            ->when($filters['medicine_id'] !== '', fn (Builder $query) => $query->where('medicines.id', (int) $filters['medicine_id']))
            ->when($filters['supplier_id'] !== '', function (Builder $query) use ($filters): void {
                $query->whereHas('batches', fn (Builder $batchQuery) => $batchQuery->where('supplier_id', (int) $filters['supplier_id']));
            })
            ->groupBy('medicines.id')
            ->orderBy('medicines.name');
    }

    private function expiryQuery(array $filters): Builder
    {
        return $this->stockQuery($filters)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', $filters['expiry_from'])
            ->whereDate('expiry_date', '<=', $filters['expiry_to']);
    }

    private function purchaseQuery(array $filters): Builder
    {
        return PurchaseOrderItem::query()
            ->with(['purchaseOrder.supplier:id,name', 'purchaseOrder.creator:id,name', 'purchaseOrder.receiver:id,name', 'medicine:id,code,name'])
            ->whereHas('purchaseOrder', function (Builder $query) use ($filters): void {
                $query
                    ->whereDate('order_date', '>=', $filters['date_from'])
                    ->whereDate('order_date', '<=', $filters['date_to'])
                    ->when($filters['supplier_id'] !== '', fn (Builder $supplierQuery) => $supplierQuery->where('supplier_id', (int) $filters['supplier_id']))
                    ->when($filters['purchase_status'] !== '', fn (Builder $statusQuery) => $statusQuery->where('status', $filters['purchase_status']));
            })
            ->when($filters['medicine_id'] !== '', fn (Builder $query) => $query->where('medicine_id', (int) $filters['medicine_id']))
            ->orderBy(
                \App\Models\PurchaseOrder::query()
                    ->select('order_date')
                    ->whereColumn('purchase_orders.id', 'purchase_order_items.purchase_order_id')
                    ->limit(1),
                'desc'
            )
            ->latest('id');
    }

    private function salesQuery(array $filters): Builder
    {
        return SaleItem::query()
            ->with(['sale.cashier:id,name', 'medicine:id,code,name,medicine_category_id', 'medicine.category:id,name'])
            ->whereHas('sale', function (Builder $query) use ($filters): void {
                $query
                    ->where('status', 'completed')
                    ->whereDate('sale_date', '>=', $filters['date_from'])
                    ->whereDate('sale_date', '<=', $filters['date_to'])
                    ->when($filters['cashier_id'] !== '', fn (Builder $cashierQuery) => $cashierQuery->where('cashier_id', (int) $filters['cashier_id']))
                    ->when($filters['payment_method'] !== '', fn (Builder $paymentQuery) => $paymentQuery->where('payment_method', $filters['payment_method']));
            })
            ->when($filters['medicine_id'] !== '', fn (Builder $query) => $query->where('medicine_id', (int) $filters['medicine_id']))
            ->orderBy(
                \App\Models\Sale::query()
                    ->select('sale_date')
                    ->whereColumn('sales.id', 'sale_items.sale_id')
                    ->limit(1),
                'desc'
            )
            ->latest('id');
    }

    private function simpleMarginQuery(array $filters): Builder
    {
        return $this->salesQuery($filters)
            ->whereHas('medicine', function (Builder $query) use ($filters): void {
                $query->when($filters['category_id'] !== '', fn (Builder $categoryQuery) => $categoryQuery->where('medicine_category_id', (int) $filters['category_id']));
            });
    }

    private function stockMovementQuery(array $filters): Builder
    {
        return StockMovement::query()
            ->with(['medicine:id,code,name', 'batch:id,batch_number,expiry_date', 'creator:id,name'])
            ->whereDate('created_at', '>=', $filters['date_from'])
            ->whereDate('created_at', '<=', $filters['date_to'])
            ->when($filters['medicine_id'] !== '', fn (Builder $query) => $query->where('medicine_id', (int) $filters['medicine_id']))
            ->when($filters['batch_id'] !== '', fn (Builder $query) => $query->where('medicine_batch_id', (int) $filters['batch_id']))
            ->when($filters['movement_type'] !== '', fn (Builder $query) => $query->where('movement_type', $filters['movement_type']))
            ->when($filters['created_by'] !== '', fn (Builder $query) => $query->where('created_by', (int) $filters['created_by']))
            ->latest('created_at')
            ->latest('id');
    }

    private function supplierQuery(array $filters): Builder
    {
        return Supplier::query()
            ->withCount(['batches', 'purchaseOrders'])
            ->when($filters['supplier_status'] === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($filters['supplier_status'] === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($filters['supplier_name'] !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$filters['supplier_name'].'%'))
            ->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(string $type, mixed $row): array
    {
        return match ($type) {
            'stock', 'expiry' => $this->batchRow($row),
            'low_stock', 'out_of_stock' => $this->medicineStockRow($row),
            'purchase' => $this->purchaseRow($row),
            'sales' => $this->salesRow($row),
            'simple_margin' => $this->marginRow($row),
            'stock_movement' => $this->movementRow($row),
            'supplier' => $this->supplierRow($row),
            default => [],
        };
    }

    private function batchRow(MedicineBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'medicine_code' => $batch->medicine?->code,
            'medicine' => $batch->medicine?->name,
            'category' => $batch->medicine?->category?->name,
            'batch_number' => $batch->batch_number,
            'supplier' => $batch->supplier?->name,
            'expiry_date' => $batch->expiry_date?->toDateString(),
            'current_stock' => (float) $batch->current_stock,
            'purchase_price' => (float) $batch->purchase_price,
            'inventory_value' => (float) $batch->current_stock * (float) $batch->purchase_price,
            'status' => $batch->status?->value,
        ];
    }

    private function medicineStockRow(Medicine $medicine): array
    {
        return [
            'id' => $medicine->id,
            'medicine_code' => $medicine->code,
            'medicine' => $medicine->name,
            'category' => $medicine->category?->name,
            'saleable_stock' => (float) $medicine->saleable_stock,
            'minimum_stock' => (float) $medicine->minimum_stock,
            'reorder_level' => (float) $medicine->reorder_level,
            'unit' => $medicine->unit?->symbol ?? $medicine->unit?->name,
            'status' => (float) $medicine->saleable_stock <= 0 ? 'out_of_stock' : 'low_stock',
        ];
    }

    private function purchaseRow(PurchaseOrderItem $item): array
    {
        return [
            'id' => $item->id,
            'code' => $item->purchaseOrder?->code,
            'order_date' => $item->purchaseOrder?->order_date?->toDateString(),
            'received_date' => $item->purchaseOrder?->received_date?->toDateString(),
            'supplier' => $item->purchaseOrder?->supplier?->name,
            'status' => $item->purchaseOrder?->status?->value,
            'medicine_code' => $item->medicine?->code,
            'medicine' => $item->medicine?->name,
            'batch_number' => $item->batch_number,
            'quantity' => (float) $item->quantity,
            'unit_cost' => (float) $item->unit_cost,
            'subtotal' => (float) $item->subtotal,
        ];
    }

    private function salesRow(SaleItem $item): array
    {
        return [
            'id' => $item->id,
            'invoice_number' => $item->sale?->invoice_number,
            'sale_date' => $item->sale?->sale_date?->toISOString(),
            'cashier' => $item->sale?->cashier?->name,
            'payment_method' => $item->sale?->payment_method?->value,
            'medicine_code' => $item->medicine_code_snapshot,
            'medicine' => $item->medicine_name_snapshot,
            'batch_number' => $item->batch_number_snapshot,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price_snapshot,
            'subtotal' => (float) $item->subtotal,
        ];
    }

    private function marginRow(SaleItem $item): array
    {
        $costTotal = (float) $item->cost_snapshot * (float) $item->quantity;

        return [
            ...$this->salesRow($item),
            'category' => $item->medicine?->category?->name,
            'cost_total' => $costTotal,
            'gross_margin' => (float) $item->gross_margin,
        ];
    }

    private function movementRow(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'created_at' => $movement->created_at?->toISOString(),
            'medicine_code' => $movement->medicine?->code,
            'medicine' => $movement->medicine?->name,
            'batch_number' => $movement->batch?->batch_number,
            'movement_type' => $movement->movement_type?->value,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'quantity_in' => (float) $movement->quantity_in,
            'quantity_out' => (float) $movement->quantity_out,
            'stock_before' => (float) $movement->stock_before,
            'stock_after' => (float) $movement->stock_after,
            'unit_cost_snapshot' => (float) $movement->unit_cost_snapshot,
            'created_by' => $movement->creator?->name,
            'description' => $movement->description,
        ];
    }

    private function supplierRow(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'contact_person' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'status' => $supplier->is_active ? 'active' : 'inactive',
            'batches_count' => $supplier->batches_count,
            'purchase_orders_count' => $supplier->purchase_orders_count,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function columns(string $type): array
    {
        return match ($type) {
            'stock', 'expiry' => [
                $this->column('medicine', 'Obat'),
                $this->column('category', 'Kategori'),
                $this->column('batch_number', 'Batch'),
                $this->column('supplier', 'Supplier'),
                $this->column('expiry_date', 'Expiry', 'date'),
                $this->column('current_stock', 'Stok', 'quantity', 'right'),
                $this->column('purchase_price', 'Harga Beli', 'currency', 'right'),
                $this->column('inventory_value', 'Nilai', 'currency', 'right'),
                $this->column('status', 'Status', 'badge'),
            ],
            'low_stock', 'out_of_stock' => [
                $this->column('medicine', 'Obat'),
                $this->column('category', 'Kategori'),
                $this->column('saleable_stock', 'Stok Jual', 'quantity', 'right'),
                $this->column('minimum_stock', 'Minimum', 'quantity', 'right'),
                $this->column('reorder_level', 'Reorder', 'quantity', 'right'),
                $this->column('unit', 'Unit'),
                $this->column('status', 'Status', 'badge'),
            ],
            'purchase' => [
                $this->column('code', 'PO'),
                $this->column('order_date', 'Tanggal PO', 'date'),
                $this->column('supplier', 'Supplier'),
                $this->column('status', 'Status', 'badge'),
                $this->column('medicine', 'Obat'),
                $this->column('batch_number', 'Batch'),
                $this->column('quantity', 'Qty', 'quantity', 'right'),
                $this->column('unit_cost', 'Cost', 'currency', 'right'),
                $this->column('subtotal', 'Subtotal', 'currency', 'right'),
            ],
            'sales' => [
                $this->column('invoice_number', 'Invoice'),
                $this->column('sale_date', 'Tanggal', 'datetime'),
                $this->column('cashier', 'Kasir'),
                $this->column('payment_method', 'Pembayaran', 'badge'),
                $this->column('medicine', 'Obat'),
                $this->column('batch_number', 'Batch'),
                $this->column('quantity', 'Qty', 'quantity', 'right'),
                $this->column('unit_price', 'Harga', 'currency', 'right'),
                $this->column('subtotal', 'Subtotal', 'currency', 'right'),
            ],
            'simple_margin' => [
                $this->column('invoice_number', 'Invoice'),
                $this->column('sale_date', 'Tanggal', 'datetime'),
                $this->column('cashier', 'Kasir'),
                $this->column('category', 'Kategori'),
                $this->column('medicine', 'Obat'),
                $this->column('quantity', 'Qty', 'quantity', 'right'),
                $this->column('subtotal', 'Revenue', 'currency', 'right'),
                $this->column('cost_total', 'Cost', 'currency', 'right'),
                $this->column('gross_margin', 'Margin', 'currency', 'right'),
            ],
            'stock_movement' => [
                $this->column('created_at', 'Waktu', 'datetime'),
                $this->column('medicine', 'Obat'),
                $this->column('batch_number', 'Batch'),
                $this->column('movement_type', 'Tipe', 'badge'),
                $this->column('reference_type', 'Reference'),
                $this->column('quantity_in', 'Masuk', 'quantity', 'right'),
                $this->column('quantity_out', 'Keluar', 'quantity', 'right'),
                $this->column('stock_after', 'Stok Akhir', 'quantity', 'right'),
                $this->column('created_by', 'User'),
            ],
            'supplier' => [
                $this->column('name', 'Supplier'),
                $this->column('contact_person', 'Kontak'),
                $this->column('phone', 'Telepon'),
                $this->column('email', 'Email'),
                $this->column('status', 'Status', 'badge'),
                $this->column('batches_count', 'Batch', 'number', 'right'),
                $this->column('purchase_orders_count', 'PO', 'number', 'right'),
            ],
            default => [],
        };
    }

    private function column(string $key, string $label, string $format = 'text', string $align = 'left'): array
    {
        return compact('key', 'label', 'format', 'align');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportRows(array $filters): array
    {
        $type = $filters['jenis_laporan'];

        return $this->query($filters)
            ->get()
            ->map(fn ($row): array => $this->mapRow($type, $row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(array $filters): array
    {
        $rows = collect($this->exportRows($filters));
        $type = $filters['jenis_laporan'];

        return match ($type) {
            'stock', 'expiry' => [
                ['label' => 'Batch', 'value' => $rows->count(), 'format' => 'number'],
                ['label' => 'Total Stok', 'value' => $rows->sum('current_stock'), 'format' => 'quantity'],
                ['label' => 'Nilai Persediaan', 'value' => $rows->sum('inventory_value'), 'format' => 'currency'],
            ],
            'low_stock', 'out_of_stock' => [
                ['label' => 'Obat', 'value' => $rows->count(), 'format' => 'number'],
                ['label' => 'Total Stok Jual', 'value' => $rows->sum('saleable_stock'), 'format' => 'quantity'],
            ],
            'purchase' => [
                ['label' => 'Item PO', 'value' => $rows->count(), 'format' => 'number'],
                ['label' => 'Total Qty', 'value' => $rows->sum('quantity'), 'format' => 'quantity'],
                ['label' => 'Subtotal', 'value' => $rows->sum('subtotal'), 'format' => 'currency'],
            ],
            'sales' => [
                ['label' => 'Item Terjual', 'value' => $rows->count(), 'format' => 'number'],
                ['label' => 'Total Qty', 'value' => $rows->sum('quantity'), 'format' => 'quantity'],
                ['label' => 'Total Penjualan', 'value' => $rows->sum('subtotal'), 'format' => 'currency'],
            ],
            'simple_margin' => [
                ['label' => 'Revenue', 'value' => $rows->sum('subtotal'), 'format' => 'currency'],
                ['label' => 'Cost', 'value' => $rows->sum('cost_total'), 'format' => 'currency'],
                ['label' => 'Margin', 'value' => $rows->sum('gross_margin'), 'format' => 'currency'],
            ],
            'stock_movement' => [
                ['label' => 'Movement', 'value' => $rows->count(), 'format' => 'number'],
                ['label' => 'Qty Masuk', 'value' => $rows->sum('quantity_in'), 'format' => 'quantity'],
                ['label' => 'Qty Keluar', 'value' => $rows->sum('quantity_out'), 'format' => 'quantity'],
            ],
            'supplier' => [
                ['label' => 'Supplier', 'value' => $rows->count(), 'format' => 'number'],
                ['label' => 'Aktif', 'value' => $rows->where('status', 'active')->count(), 'format' => 'number'],
                ['label' => 'Nonaktif', 'value' => $rows->where('status', 'inactive')->count(), 'format' => 'number'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function exportPayload(array $filters): array
    {
        $type = $filters['jenis_laporan'];
        $columns = $this->columns($type);
        $rows = $this->exportRows($filters);

        return [
            'title' => 'Laporan '.self::REPORTS[$type],
            'filters' => $filters,
            'columns' => $columns,
            'headings' => array_column($columns, 'label'),
            'rows' => collect($rows)
                ->map(fn (array $row): array => collect($columns)
                    ->map(fn (array $column): string => $this->exportValue($row[$column['key']] ?? null, $column['format']))
                    ->all())
                ->all(),
        ];
    }

    private function exportValue(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($format) {
            'currency' => number_format((float) $value, 2, '.', ''),
            'quantity' => number_format((float) $value, 3, '.', ''),
            'number' => (string) (int) $value,
            'date' => Carbon::parse($value)->toDateString(),
            'datetime' => Carbon::parse($value)->timezone(config('app.timezone'))->toDateTimeString(),
            default => str_replace('_', ' ', (string) $value),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildXlsx(array $payload): string
    {
        $sheetRows = [
            [$payload['title']],
            ['Dibuat pada', Carbon::now(config('app.timezone'))->toDateTimeString()],
            [],
            $payload['headings'],
            ...$payload['rows'],
        ];

        $sheetData = collect($sheetRows)
            ->map(function (array $row, int $rowIndex): string {
                $cells = collect($row)
                    ->map(function (mixed $value, int $columnIndex) use ($rowIndex): string {
                        $cell = $this->columnLetter($columnIndex + 1).($rowIndex + 1);
                        $value = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');

                        return '<c r="'.$cell.'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
                    })
                    ->implode('');

                return '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
            })
            ->implode('');

        $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetData.'</sheetData>'
            .'</worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'report-xlsx-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRels());
        $zip->addFromString('docProps/app.xml', $this->xlsxAppProps());
        $zip->addFromString('docProps/core.xml', $this->xlsxCoreProps());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRels());
        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
        $zip->close();

        $content = file_get_contents($path) ?: '';
        @unlink($path);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildPdf(array $payload): string
    {
        $lines = [
            $payload['title'],
            'Dibuat pada: '.Carbon::now(config('app.timezone'))->toDateTimeString(),
            '',
            implode(' | ', $payload['headings']),
            str_repeat('-', 140),
        ];

        foreach ($payload['rows'] as $row) {
            $lines[] = implode(' | ', $row);
        }

        $wrappedLines = collect($lines)
            ->flatMap(fn (string $line): array => explode("\n", wordwrap($line, 130, "\n", true)))
            ->all();
        $pages = array_chunk($wrappedLines, 42);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pageRefs = [];
        $nextObject = 4;

        foreach ($pages as $pageLines) {
            $stream = "BT\n/F1 8 Tf\n12 TL\n30 560 Td\n";

            foreach ($pageLines as $line) {
                $stream .= '('.$this->pdfEscape($line).") Tj\nT*\n";
            }

            $stream .= 'ET';

            $contentObject = $nextObject++;
            $pageObject = $nextObject++;
            $objects[$contentObject] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentObject.' 0 R >>';
            $pageRefs[] = $pageObject.' 0 R';
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageRefs).'] /Count '.count($pageRefs).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxObject + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= str_pad((string) ($offsets[$i] ?? 0), 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".($maxObject + 1)." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }

    private function filename(array $filters, string $format): string
    {
        $type = str_replace('_', '-', $filters['jenis_laporan']);
        $datePart = match (true) {
            in_array($filters['jenis_laporan'], self::DATE_REPORTS, true) => $filters['date_from'].'-'.$filters['date_to'],
            $filters['jenis_laporan'] === 'expiry' => $filters['expiry_from'].'-'.$filters['expiry_to'],
            default => $this->today()->toDateString(),
        };

        return "laporan-{$type}-{$datePart}.{$format}";
    }

    private function pdfEscape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function columnLetter(int $number): string
    {
        $letter = '';

        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)).$letter;
            $number = intdiv($number, 26);
        }

        return $letter;
    }

    private function xlsxContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>';
    }

    private function xlsxRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function xlsxWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Laporan" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function xlsxWorkbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }

    private function xlsxAppProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Inventaris Toko Obat</Application>'
            .'</Properties>';
    }

    private function xlsxCoreProps(): string
    {
        $created = Carbon::now(config('app.timezone'))->toISOString();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>Laporan Inventaris Toko Obat</dc:title>'
            .'<dc:creator>Inventaris Toko Obat</dc:creator>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$created.'</dcterms:created>'
            .'</cp:coreProperties>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categories(): array
    {
        return MedicineCategory::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (MedicineCategory $category): array => ['value' => $category->id, 'label' => $category->name])
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
            ->map(fn (Medicine $medicine): array => ['value' => $medicine->id, 'label' => "{$medicine->code} - {$medicine->name}"])
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
            ->map(fn (Supplier $supplier): array => ['value' => $supplier->id, 'label' => $supplier->name])
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
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cashiers(): array
    {
        return User::query()
            ->whereIn('id', \App\Models\Sale::query()->select('cashier_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function movementCreators(): array
    {
        return User::query()
            ->whereIn('id', StockMovement::query()->select('created_by')->distinct())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['value' => $user->id, 'label' => $user->name])
            ->all();
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return array<int, array<string, string>>
     */
    private function enumOptions(array $cases): array
    {
        return collect($cases)
            ->map(fn (\BackedEnum $case): array => [
                'value' => (string) $case->value,
                'label' => str((string) $case->value)->replace('_', ' ')->title()->toString(),
            ])
            ->all();
    }

    private function dateOrDefault(string $value, Carbon $default): string
    {
        if ($value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value, config('app.timezone'))->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    private function expiryWarningDays(): int
    {
        return (int) (Setting::query()->where('key', 'expiry_warning_days')->value('value') ?: 60);
    }

    private function today(): Carbon
    {
        return Carbon::today(config('app.timezone'));
    }
}
