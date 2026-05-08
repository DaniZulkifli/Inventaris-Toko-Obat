<?php

namespace Tests\Unit;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\ReportService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use App\Services\StockService;
use App\Services\StockUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase17BackendLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-08 12:00:00', 'Asia/Makassar'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_purchase_order_subtotal_total_and_auto_code_are_backend_calculated(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $medicineA = Medicine::factory()->create(['is_active' => true]);
        $medicineB = Medicine::factory()->create(['is_active' => true]);

        $purchaseOrder = app(PurchaseOrderService::class)->createDraft([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-05-08',
            'discount' => 500,
            'notes' => null,
            'items' => [
                [
                    'medicine_id' => $medicineA->id,
                    'batch_number' => 'BAT-UNIT-001',
                    'expiry_date' => '2027-01-01',
                    'quantity' => 2,
                    'unit_cost' => 1000,
                ],
                [
                    'medicine_id' => $medicineB->id,
                    'batch_number' => 'BAT-UNIT-002',
                    'expiry_date' => '2027-02-01',
                    'quantity' => 3,
                    'unit_cost' => 2500,
                ],
            ],
        ], $admin);

        $this->assertSame('PO-20260508-0001', $purchaseOrder->code);
        $this->assertSame('9500.00', $purchaseOrder->subtotal);
        $this->assertSame('500.00', $purchaseOrder->discount);
        $this->assertSame('9000.00', $purchaseOrder->total_amount);
        $this->assertEqualsCanonicalizing(['2000.00', '7500.00'], $purchaseOrder->items->pluck('subtotal')->all());
    }

    public function test_sale_subtotal_total_cash_change_margin_and_invoice_are_backend_calculated(): void
    {
        $cashier = User::factory()->create();
        $medicine = Medicine::factory()->create([
            'selling_price' => '1000.00',
            'is_active' => true,
        ]);
        MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'BAT-SALE-001',
            'expiry_date' => '2026-06-01',
            'purchase_price' => '600.00',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $sale = app(SaleService::class)->complete([
            'customer_name' => 'Pelanggan Unit',
            'payment_method' => 'cash',
            'discount' => 100,
            'amount_paid' => 5000,
            'notes' => null,
            'items' => [
                [
                    'medicine_id' => $medicine->id,
                    'medicine_batch_id' => null,
                    'quantity' => 3,
                ],
            ],
        ], $cashier);

        $this->assertSame('INV-20260508-0001', $sale->invoice_number);
        $this->assertSame('completed', $sale->status->value);
        $this->assertSame('3000.00', $sale->subtotal);
        $this->assertSame('2900.00', $sale->total_amount);
        $this->assertSame('5000.00', $sale->amount_paid);
        $this->assertSame('2100.00', $sale->change_amount);
        $this->assertSame('1200.00', $sale->gross_margin);
        $this->assertSame('1200.00', $sale->items->first()->gross_margin);
    }

    public function test_stock_usage_cost_and_adjustment_difference_are_backend_calculated(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'purchase_price' => '1250.00',
            'current_stock' => '10.000',
            'status' => 'available',
        ]);

        $usage = app(StockUsageService::class)->createDraft([
            'usage_date' => '2026-05-08',
            'usage_type' => 'damaged',
            'reason' => 'Unit test usage',
            'items' => [
                [
                    'medicine_batch_id' => $batch->id,
                    'quantity' => 2,
                    'notes' => null,
                ],
            ],
        ], $admin);

        $this->assertSame('USE-20260508-0001', $usage->code);
        $this->assertSame('2500.00', $usage->estimated_total_cost);
        $this->assertSame('2500.00', $usage->items->first()->estimated_cost);

        $adjustment = app(StockAdjustmentService::class)->createDraft([
            'adjustment_date' => '2026-05-08',
            'reason' => 'Unit test opname',
            'items' => [
                [
                    'medicine_batch_id' => $batch->id,
                    'counted_stock' => 7,
                    'notes' => null,
                ],
            ],
        ], $admin);

        $item = $adjustment->items->first();
        $this->assertSame('ADJ-20260508-0001', $adjustment->code);
        $this->assertSame('10.000', $item->system_stock);
        $this->assertSame('7.000', $item->counted_stock);
        $this->assertSame('-3.000', $item->difference);
        $this->assertSame('1250.00', $item->cost_snapshot);
    }

    public function test_fefo_stock_status_and_expiry_detection_are_covered(): void
    {
        $stockService = app(StockService::class);
        $reportService = app(ReportService::class);
        $fefoMedicine = Medicine::factory()->create([
            'name' => 'Obat FEFO Unit',
            'minimum_stock' => '1.000',
            'is_active' => true,
        ]);
        $older = MedicineBatch::factory()->for($fefoMedicine)->create([
            'batch_number' => 'BAT-FEFO-OLD',
            'expiry_date' => '2026-05-20',
            'current_stock' => '2.000',
            'status' => 'available',
        ]);
        $newer = MedicineBatch::factory()->for($fefoMedicine)->create([
            'batch_number' => 'BAT-FEFO-NEW',
            'expiry_date' => '2026-06-20',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);
        $lowMedicine = Medicine::factory()->create([
            'name' => 'Obat Menipis Unit',
            'minimum_stock' => '5.000',
            'is_active' => true,
        ]);
        MedicineBatch::factory()->for($lowMedicine)->create([
            'batch_number' => 'BAT-LOW-UNIT',
            'expiry_date' => '2026-09-01',
            'current_stock' => '4.000',
            'status' => 'available',
        ]);
        Medicine::factory()->create([
            'name' => 'Obat Habis Unit',
            'minimum_stock' => '5.000',
            'is_active' => true,
        ]);
        MedicineBatch::factory()->create([
            'batch_number' => 'BAT-NEAR-UNIT',
            'expiry_date' => '2026-05-15',
            'current_stock' => '3.000',
            'status' => 'available',
        ]);
        $expired = MedicineBatch::factory()->create([
            'batch_number' => 'BAT-EXPIRED-UNIT',
            'expiry_date' => '2026-05-01',
            'current_stock' => '3.000',
            'status' => 'expired',
        ]);

        $allocations = $stockService->allocateFefoBatches($fefoMedicine, 4);
        $this->assertSame($older->id, $allocations[0]['batch']->id);
        $this->assertSame(2.0, $allocations[0]['quantity']);
        $this->assertSame($newer->id, $allocations[1]['batch']->id);
        $this->assertSame(2.0, $allocations[1]['quantity']);

        $lowReport = $reportService->report($reportService->normalizeFilters(['jenis_laporan' => 'low_stock'], 'super_admin'));
        $this->assertTrue(collect($lowReport['rows']->items())->contains(fn (array $row): bool => $row['medicine'] === 'Obat Menipis Unit'));

        $outReport = $reportService->report($reportService->normalizeFilters(['jenis_laporan' => 'out_of_stock'], 'super_admin'));
        $this->assertTrue(collect($outReport['rows']->items())->contains(fn (array $row): bool => $row['medicine'] === 'Obat Habis Unit'));

        $nearReport = $reportService->report($reportService->normalizeFilters([
            'jenis_laporan' => 'expiry',
            'expiry_from' => '2026-05-10',
            'expiry_to' => '2026-05-16',
        ], 'super_admin'));
        $this->assertSame(['BAT-NEAR-UNIT'], collect($nearReport['rows']->items())->pluck('batch_number')->all());

        $this->assertSame('expired', $stockService->resolveBatchStatus($expired)->value);
    }

    public function test_services_reject_negative_or_zero_numeric_values(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $medicine = Medicine::factory()->create(['is_active' => true]);
        $batch = MedicineBatch::factory()->for($medicine)->create([
            'current_stock' => '10.000',
            'status' => 'available',
        ]);

        $this->assertValidationFails('items.0.quantity', fn () => app(PurchaseOrderService::class)->createDraft([
            'supplier_id' => $supplier->id,
            'order_date' => '2026-05-08',
            'discount' => 0,
            'notes' => null,
            'items' => [[
                'medicine_id' => $medicine->id,
                'batch_number' => 'BAT-NEG-PO',
                'expiry_date' => '2027-01-01',
                'quantity' => 0,
                'unit_cost' => 1000,
            ]],
        ], $admin));

        $this->assertValidationFails('discount', fn () => app(SaleService::class)->complete([
            'payment_method' => 'cash',
            'discount' => -1,
            'amount_paid' => 10000,
            'items' => [[
                'medicine_id' => $medicine->id,
                'medicine_batch_id' => $batch->id,
                'quantity' => 1,
            ]],
        ], $admin));

        $this->assertValidationFails('items.0.counted_stock', fn () => app(StockAdjustmentService::class)->createDraft([
            'adjustment_date' => '2026-05-08',
            'reason' => 'Invalid counted stock',
            'items' => [[
                'medicine_batch_id' => $batch->id,
                'counted_stock' => -1,
                'notes' => null,
            ]],
        ], $admin));
    }

    private function assertValidationFails(string $key, callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());

            return;
        }

        $this->fail("Expected validation error for {$key}.");
    }
}
