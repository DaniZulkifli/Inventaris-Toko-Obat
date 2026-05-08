<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase10PurchaseOrderTest extends TestCase
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

    public function test_purchase_order_page_lists_data_and_options(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['is_active' => true]);
        Medicine::factory()->create(['is_active' => true]);
        PurchaseOrder::factory()->for($supplier)->for($admin, 'creator')->create([
            'code' => 'PO-20260508-0001',
        ]);

        $this
            ->actingAs($admin)
            ->get(route('purchase-orders.index', ['search' => 'PO-20260508']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseOrders/Index')
                ->has('purchaseOrders.data', 1)
                ->has('options.active_suppliers', 1)
                ->has('options.medicines', 1)
            );
    }

    public function test_admin_can_create_update_and_delete_draft_without_stock_movement(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $medicine = Medicine::factory()->create([
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('purchase-orders.store'), [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-05-08',
                'discount' => 500,
                'notes' => 'Draft restock',
                'items' => [
                    [
                        'medicine_id' => $medicine->id,
                        'batch_number' => 'BAT-PO-DRAFT',
                        'expiry_date' => '2026-08-31',
                        'quantity' => 10,
                        'unit_cost' => 1500,
                    ],
                ],
            ])
            ->assertRedirect();

        $purchaseOrder = PurchaseOrder::query()->where('code', 'PO-20260508-0001')->firstOrFail();

        $this->assertSame('draft', $purchaseOrder->status->value);
        $this->assertSame('15000.00', $purchaseOrder->subtotal);
        $this->assertSame('14500.00', $purchaseOrder->total_amount);
        $this->assertSame(0, StockMovement::query()->count());

        $this
            ->actingAs($admin)
            ->patch(route('purchase-orders.update', $purchaseOrder), [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-05-08',
                'discount' => 0,
                'notes' => 'Draft update',
                'items' => [
                    [
                        'medicine_id' => $medicine->id,
                        'batch_number' => '',
                        'expiry_date' => '2026-09-30',
                        'quantity' => 2,
                        'unit_cost' => 2000,
                    ],
                ],
            ])
            ->assertRedirect();

        $purchaseOrder->refresh();
        $this->assertSame('4000.00', $purchaseOrder->subtotal);
        $this->assertSame('4000.00', $purchaseOrder->total_amount);
        $this->assertStringStartsWith('AUTO-20260508-', $purchaseOrder->items()->firstOrFail()->batch_number);
        $this->assertSame(0, StockMovement::query()->count());

        $this
            ->actingAs($admin)
            ->delete(route('purchase-orders.destroy', $purchaseOrder))
            ->assertRedirect();

        $this->assertDatabaseMissing('purchase_orders', ['id' => $purchaseOrder->id]);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_receiving_purchase_order_updates_existing_batch_creates_new_batches_and_movements(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $existingMedicine = Medicine::factory()->create([
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        $newMedicine = Medicine::factory()->create([
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        $alkes = Medicine::factory()->create([
            'classification' => 'alkes',
            'is_active' => true,
        ]);
        $existingBatch = MedicineBatch::factory()->for($existingMedicine)->for($supplier)->create([
            'batch_number' => 'BAT-EXIST',
            'expiry_date' => '2026-06-30',
            'purchase_price' => 1000,
            'initial_stock' => '5.000',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('purchase-orders.store'), [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-05-08',
                'discount' => 1000,
                'notes' => 'Receive stok',
                'items' => [
                    [
                        'medicine_id' => $existingMedicine->id,
                        'batch_number' => 'BAT-EXIST',
                        'expiry_date' => '2026-06-30',
                        'quantity' => 3,
                        'unit_cost' => 1200,
                    ],
                    [
                        'medicine_id' => $newMedicine->id,
                        'batch_number' => 'BAT-NEW',
                        'expiry_date' => '2026-08-31',
                        'quantity' => 4,
                        'unit_cost' => 2000,
                    ],
                    [
                        'medicine_id' => $alkes->id,
                        'batch_number' => '',
                        'expiry_date' => '',
                        'quantity' => 2,
                        'unit_cost' => 25000,
                    ],
                ],
            ])
            ->assertRedirect();

        $purchaseOrder = PurchaseOrder::query()->where('code', 'PO-20260508-0001')->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('purchase-orders.receive', $purchaseOrder))
            ->assertRedirect();

        $purchaseOrder->refresh();
        $existingBatch->refresh();
        $newBatch = MedicineBatch::query()->where('batch_number', 'BAT-NEW')->firstOrFail();
        $autoBatch = MedicineBatch::query()->where('medicine_id', $alkes->id)->where('batch_number', 'like', 'AUTO-20260508-%')->firstOrFail();

        $this->assertSame('received', $purchaseOrder->status->value);
        $this->assertSame($admin->id, $purchaseOrder->received_by);
        $this->assertSame('2026-05-08', $purchaseOrder->received_date->toDateString());
        $this->assertSame('8.000', $existingBatch->current_stock);
        $this->assertSame('1200.00', $existingBatch->purchase_price);
        $this->assertSame('4.000', $newBatch->current_stock);
        $this->assertSame('available', $newBatch->status->value);
        $this->assertNull($autoBatch->expiry_date);
        $this->assertSame('2.000', $autoBatch->current_stock);
        $this->assertSame(3, StockMovement::query()->where('reference_type', 'purchase_orders')->where('reference_id', $purchaseOrder->id)->count());

        $movement = StockMovement::query()
            ->where('medicine_batch_id', $existingBatch->id)
            ->where('reference_id', $purchaseOrder->id)
            ->firstOrFail();

        $this->assertSame('purchase_in', $movement->movement_type->value);
        $this->assertSame('3.000', $movement->quantity_in);
        $this->assertSame('5.000', $movement->stock_before);
        $this->assertSame('8.000', $movement->stock_after);
        $this->assertSame('1200.00', $movement->unit_cost_snapshot);
    }

    public function test_received_purchase_order_is_locked_and_inactive_supplier_cannot_be_received(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $inactiveSupplier = Supplier::factory()->create(['is_active' => false]);
        $medicine = Medicine::factory()->create([
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('purchase-orders.store'), [
                'supplier_id' => $supplier->id,
                'order_date' => '2026-05-08',
                'discount' => 0,
                'notes' => null,
                'items' => [
                    [
                        'medicine_id' => $medicine->id,
                        'batch_number' => 'BAT-LOCKED',
                        'expiry_date' => '2026-08-31',
                        'quantity' => 1,
                        'unit_cost' => 1000,
                    ],
                ],
            ])
            ->assertRedirect();

        $received = PurchaseOrder::query()->where('code', 'PO-20260508-0001')->firstOrFail();

        $this->actingAs($admin)->post(route('purchase-orders.receive', $received))->assertRedirect();

        $payload = [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-05-08',
            'discount' => 0,
            'notes' => null,
            'items' => [
                [
                    'medicine_id' => $medicine->id,
                    'batch_number' => 'BAT-LOCKED',
                    'expiry_date' => '2026-08-31',
                    'quantity' => 2,
                    'unit_cost' => 1000,
                ],
            ],
        ];

        $this->actingAs($admin)->patch(route('purchase-orders.update', $received), $payload)->assertSessionHasErrors('status');
        $this->actingAs($admin)->delete(route('purchase-orders.destroy', $received))->assertSessionHasErrors('status');
        $this->actingAs($admin)->post(route('purchase-orders.receive', $received))->assertSessionHasErrors('status');

        $draftWithInactiveSupplier = PurchaseOrder::factory()
            ->for($inactiveSupplier)
            ->for($admin, 'creator')
            ->create([
                'status' => 'draft',
                'received_by' => null,
                'received_date' => null,
            ]);
        $draftWithInactiveSupplier->items()->create([
            'medicine_id' => $medicine->id,
            'medicine_batch_id' => null,
            'batch_number' => 'BAT-INACTIVE-SUPPLIER',
            'expiry_date' => '2026-09-30',
            'quantity' => '1.000',
            'unit_cost' => '1000.00',
            'subtotal' => '1000.00',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('purchase-orders.receive', $draftWithInactiveSupplier))
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_employee_cannot_manage_purchase_orders(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->get(route('purchase-orders.index'))->assertForbidden();
        $this->actingAs($employee)->post(route('purchase-orders.store'), [])->assertForbidden();
    }
}
