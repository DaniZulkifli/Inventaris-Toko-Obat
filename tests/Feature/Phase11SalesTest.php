<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase11SalesTest extends TestCase
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

    public function test_sales_page_scopes_employee_history_and_admin_can_view_operational_history(): void
    {
        $employee = User::factory()->create();
        $otherCashier = User::factory()->create();
        $admin = User::factory()->admin()->create();

        Sale::factory()->for($employee, 'cashier')->create(['invoice_number' => 'INV-20260508-0001']);
        Sale::factory()->for($otherCashier, 'cashier')->create(['invoice_number' => 'INV-20260508-0002']);

        $this
            ->actingAs($employee)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->where('historyScope', 'mine')
                ->where('canCreate', true)
                ->has('sales.data', 1)
            );

        $this
            ->actingAs($employee)
            ->get(route('sales.my-history'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->where('historyScope', 'mine')
                ->where('canCreate', false)
                ->has('sales.data', 1)
            );

        $this
            ->actingAs($admin)
            ->get(route('sales.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Sales/Index')
                ->where('historyScope', 'all')
                ->has('sales.data', 2)
            );
    }

    public function test_completed_sale_uses_fefo_creates_snapshots_movements_and_cash_change(): void
    {
        $cashier = User::factory()->create();
        $medicine = Medicine::factory()->create([
            'code' => 'MED-SALE-FEFO',
            'name' => 'Obat FEFO',
            'selling_price' => '1000.00',
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        $older = MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'BAT-OLDER',
            'expiry_date' => '2026-05-20',
            'purchase_price' => '500.00',
            'initial_stock' => '2.000',
            'current_stock' => '2.000',
            'status' => 'available',
        ]);
        $newer = MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'BAT-NEWER',
            'expiry_date' => '2026-06-20',
            'purchase_price' => '600.00',
            'initial_stock' => '5.000',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'customer_name' => 'Pelanggan Test',
                'payment_method' => 'cash',
                'discount' => 100,
                'amount_paid' => 5000,
                'notes' => 'Tunai',
                'items' => [
                    [
                        'medicine_id' => $medicine->id,
                        'medicine_batch_id' => null,
                        'quantity' => 3,
                    ],
                ],
            ])
            ->assertRedirect();

        $sale = Sale::query()->where('invoice_number', 'INV-20260508-0001')->firstOrFail();
        $older->refresh();
        $newer->refresh();

        $this->assertSame('completed', $sale->status->value);
        $this->assertSame('3000.00', $sale->subtotal);
        $this->assertSame('2900.00', $sale->total_amount);
        $this->assertSame('5000.00', $sale->amount_paid);
        $this->assertSame('2100.00', $sale->change_amount);
        $this->assertSame('1400.00', $sale->gross_margin);
        $this->assertSame('0.000', $older->current_stock);
        $this->assertSame('4.000', $newer->current_stock);

        $items = $sale->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($older->id, $items[0]->medicine_batch_id);
        $this->assertSame('2.000', $items[0]->quantity);
        $this->assertSame('BAT-OLDER', $items[0]->batch_number_snapshot);
        $this->assertSame($newer->id, $items[1]->medicine_batch_id);
        $this->assertSame('1.000', $items[1]->quantity);

        $this->assertSame(2, StockMovement::query()
            ->where('movement_type', 'sale_out')
            ->where('reference_type', 'sales')
            ->where('reference_id', $sale->id)
            ->count());
    }

    public function test_manual_batch_uses_batch_price_and_non_cash_payment_is_forced_to_total(): void
    {
        $cashier = User::factory()->create();
        $medicine = Medicine::factory()->create([
            'selling_price' => '1000.00',
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        $batch = MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'BAT-MANUAL',
            'expiry_date' => '2026-07-31',
            'purchase_price' => '700.00',
            'selling_price' => '1500.00',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'customer_name' => null,
                'payment_method' => 'qris',
                'discount' => 500,
                'amount_paid' => 1,
                'notes' => null,
                'items' => [
                    [
                        'medicine_id' => $medicine->id,
                        'medicine_batch_id' => $batch->id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $sale = Sale::query()->firstOrFail();
        $item = $sale->items()->firstOrFail();

        $this->assertSame('2500.00', $sale->total_amount);
        $this->assertSame('2500.00', $sale->amount_paid);
        $this->assertSame('0.00', $sale->change_amount);
        $this->assertSame('1500.00', $item->unit_price_snapshot);
        $this->assertSame('2.000', $item->quantity);
        $this->assertSame('3.000', $batch->refresh()->current_stock);
    }

    public function test_sale_rejects_unsaleable_manual_batch_and_insufficient_cash_without_stock_change(): void
    {
        $cashier = User::factory()->create();
        $medicine = Medicine::factory()->create([
            'selling_price' => '1000.00',
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        $expired = MedicineBatch::factory()->for($medicine)->create([
            'expiry_date' => '2026-05-01',
            'current_stock' => '5.000',
            'status' => 'expired',
        ]);
        $valid = MedicineBatch::factory()->for($medicine)->create([
            'expiry_date' => '2026-08-31',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'payment_method' => 'cash',
                'discount' => 0,
                'amount_paid' => 1000,
                'items' => [
                    [
                        'medicine_id' => $medicine->id,
                        'medicine_batch_id' => $expired->id,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertSessionHasErrors('stock');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, StockMovement::query()->count());

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'payment_method' => 'cash',
                'discount' => 0,
                'amount_paid' => 1000,
                'items' => [
                    [
                        'medicine_id' => $medicine->id,
                        'medicine_batch_id' => $valid->id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertSessionHasErrors('amount_paid');

        $this->assertSame('5.000', $valid->refresh()->current_stock);
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_sale_rejects_empty_items_inactive_medicine_and_insufficient_stock(): void
    {
        $cashier = User::factory()->create();
        $inactiveMedicine = Medicine::factory()->create([
            'is_active' => false,
        ]);
        $activeMedicine = Medicine::factory()->create([
            'selling_price' => '1000.00',
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        MedicineBatch::factory()->for($activeMedicine)->create([
            'expiry_date' => '2026-08-31',
            'current_stock' => '1.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'payment_method' => 'cash',
                'amount_paid' => 1000,
                'items' => [],
            ])
            ->assertSessionHasErrors('items');

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'payment_method' => 'cash',
                'amount_paid' => 1000,
                'items' => [
                    [
                        'medicine_id' => $inactiveMedicine->id,
                        'medicine_batch_id' => null,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.medicine_id');

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'payment_method' => 'cash',
                'amount_paid' => 5000,
                'items' => [
                    [
                        'medicine_id' => $activeMedicine->id,
                        'medicine_batch_id' => null,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertSessionHasErrors('stock');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }
}
