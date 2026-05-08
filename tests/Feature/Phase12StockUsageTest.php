<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\StockMovement;
use App\Models\StockUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase12StockUsageTest extends TestCase
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

    public function test_stock_usage_page_lists_data_and_options_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'current_stock' => '5.000',
        ]);
        StockUsage::factory()->for($admin, 'creator')->create([
            'code' => 'USE-20260508-0001',
        ]);

        $this
            ->actingAs($admin)
            ->get(route('stock-usages.index', ['search' => 'USE-20260508']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StockUsages/Index')
                ->has('stockUsages.data', 1)
                ->has('options.usage_types', 7)
                ->has('options.batches', 1)
            );

        $this->assertSame('5.000', $batch->refresh()->current_stock);
    }

    public function test_admin_can_create_update_and_delete_draft_without_stock_movement(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'purchase_price' => '1200.00',
            'current_stock' => '10.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-usages.store'), [
                'usage_date' => '2026-05-08',
                'usage_type' => 'damaged',
                'reason' => 'Kemasan rusak',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'quantity' => 2,
                        'notes' => 'Botol pecah',
                    ],
                ],
            ])
            ->assertRedirect();

        $stockUsage = StockUsage::query()->where('code', 'USE-20260508-0001')->firstOrFail();

        $this->assertSame('draft', $stockUsage->status->value);
        $this->assertSame('2400.00', $stockUsage->estimated_total_cost);
        $this->assertSame('10.000', $batch->refresh()->current_stock);
        $this->assertSame(0, StockMovement::query()->count());

        $this
            ->actingAs($admin)
            ->patch(route('stock-usages.update', $stockUsage), [
                'usage_date' => '2026-05-08',
                'usage_type' => 'internal_use',
                'reason' => 'Dipakai internal',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'quantity' => 3,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertRedirect();

        $stockUsage->refresh();
        $this->assertSame('internal_use', $stockUsage->usage_type->value);
        $this->assertSame('3600.00', $stockUsage->estimated_total_cost);
        $this->assertSame('10.000', $batch->refresh()->current_stock);
        $this->assertSame(0, StockMovement::query()->count());

        $this
            ->actingAs($admin)
            ->delete(route('stock-usages.destroy', $stockUsage))
            ->assertRedirect();

        $this->assertDatabaseMissing('stock_usages', ['id' => $stockUsage->id]);
        $this->assertSame('10.000', $batch->refresh()->current_stock);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_complete_stock_usage_reduces_stock_and_locks_completed_record(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'purchase_price' => '1500.00',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-usages.store'), [
                'usage_date' => '2026-05-08',
                'usage_type' => 'expired',
                'reason' => 'Obat rusak saat display',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'quantity' => 2,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertRedirect();

        $stockUsage = StockUsage::query()->firstOrFail();

        $this
            ->actingAs($admin)
            ->post(route('stock-usages.complete', $stockUsage))
            ->assertRedirect();

        $stockUsage->refresh();
        $batch->refresh();

        $this->assertSame('completed', $stockUsage->status->value);
        $this->assertSame($admin->id, $stockUsage->completed_by);
        $this->assertSame('3000.00', $stockUsage->estimated_total_cost);
        $this->assertSame('3.000', $batch->current_stock);

        $movement = StockMovement::query()->where('reference_id', $stockUsage->id)->firstOrFail();
        $this->assertSame('usage_out', $movement->movement_type->value);
        $this->assertSame('2.000', $movement->quantity_out);
        $this->assertSame('5.000', $movement->stock_before);
        $this->assertSame('3.000', $movement->stock_after);
        $this->assertSame('1500.00', $movement->unit_cost_snapshot);

        $payload = [
            'usage_date' => '2026-05-08',
            'usage_type' => 'lost',
            'reason' => 'Tidak boleh update',
            'items' => [
                [
                    'medicine_batch_id' => $batch->id,
                    'quantity' => 1,
                    'notes' => null,
                ],
            ],
        ];

        $this->actingAs($admin)->patch(route('stock-usages.update', $stockUsage), $payload)->assertSessionHasErrors('status');
        $this->actingAs($admin)->delete(route('stock-usages.destroy', $stockUsage))->assertSessionHasErrors('status');
        $this->actingAs($admin)->post(route('stock-usages.complete', $stockUsage))->assertSessionHasErrors('status');
        $this->actingAs($admin)->post(route('stock-usages.cancel', $stockUsage), ['cancel_reason' => 'Admin mencoba cancel'])->assertForbidden();
    }

    public function test_super_admin_can_cancel_completed_stock_usage_with_reason_and_restore_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $batch = MedicineBatch::factory()->create([
            'purchase_price' => '2000.00',
            'current_stock' => '7.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-usages.store'), [
                'usage_date' => '2026-05-08',
                'usage_type' => 'sample',
                'reason' => 'Sample event',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'quantity' => 3,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertRedirect();

        $stockUsage = StockUsage::query()->firstOrFail();
        $this->actingAs($admin)->post(route('stock-usages.complete', $stockUsage))->assertRedirect();

        $this
            ->actingAs($superAdmin)
            ->post(route('stock-usages.cancel', $stockUsage), [
                'cancel_reason' => 'Salah input sample',
            ])
            ->assertRedirect();

        $stockUsage->refresh();
        $batch->refresh();

        $this->assertSame('cancelled', $stockUsage->status->value);
        $this->assertStringContainsString('Pembatalan: Salah input sample', $stockUsage->reason);
        $this->assertSame('7.000', $batch->current_stock);

        $this->assertSame(1, StockMovement::query()->where('movement_type', 'usage_out')->where('reference_id', $stockUsage->id)->count());
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'cancel_usage')->where('reference_id', $stockUsage->id)->count());

        $this
            ->actingAs($superAdmin)
            ->post(route('stock-usages.cancel', $stockUsage), [
                'cancel_reason' => 'Cancel ulang',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_stock_usage_validates_reason_items_quantity_and_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'current_stock' => '1.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-usages.store'), [
                'usage_date' => '2026-05-08',
                'usage_type' => 'damaged',
                'reason' => '',
                'items' => [],
            ])
            ->assertSessionHasErrors(['reason', 'items']);

        $this
            ->actingAs($admin)
            ->post(route('stock-usages.store'), [
                'usage_date' => '2026-05-08',
                'usage_type' => 'damaged',
                'reason' => 'Rusak',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'quantity' => 0,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.quantity');

        $this
            ->actingAs($admin)
            ->post(route('stock-usages.store'), [
                'usage_date' => '2026-05-08',
                'usage_type' => 'damaged',
                'reason' => 'Rusak',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'quantity' => 2,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.quantity');

        $this->assertSame(0, StockUsage::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }
}
