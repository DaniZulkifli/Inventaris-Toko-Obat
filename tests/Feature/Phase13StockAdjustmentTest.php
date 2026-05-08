<?php

namespace Tests\Feature;

use App\Models\MedicineBatch;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase13StockAdjustmentTest extends TestCase
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

    public function test_stock_adjustment_page_lists_data_and_options(): void
    {
        $admin = User::factory()->admin()->create();
        MedicineBatch::factory()->create(['current_stock' => '5.000']);
        StockAdjustment::factory()->for($admin, 'creator')->create([
            'code' => 'ADJ-20260508-0001',
        ]);

        $this
            ->actingAs($admin)
            ->get(route('stock-adjustments.index', ['search' => 'ADJ-20260508']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StockAdjustments/Index')
                ->has('adjustments.data', 1)
                ->has('options.statuses', 3)
                ->has('options.batches', 1)
            );
    }

    public function test_admin_can_create_and_update_draft_without_stock_movement(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'purchase_price' => '1500.00',
            'current_stock' => '10.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-adjustments.store'), [
                'adjustment_date' => '2026-05-08',
                'reason' => 'Stock opname etalase',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'counted_stock' => 8,
                        'notes' => 'Selisih dua',
                    ],
                ],
            ])
            ->assertRedirect();

        $adjustment = StockAdjustment::query()->where('code', 'ADJ-20260508-0001')->firstOrFail();
        $item = $adjustment->items()->firstOrFail();

        $this->assertSame('draft', $adjustment->status->value);
        $this->assertSame('10.000', $item->system_stock);
        $this->assertSame('8.000', $item->counted_stock);
        $this->assertSame('-2.000', $item->difference);
        $this->assertSame('1500.00', $item->cost_snapshot);
        $this->assertSame('10.000', $batch->refresh()->current_stock);
        $this->assertSame(0, StockMovement::query()->count());

        $this
            ->actingAs($admin)
            ->patch(route('stock-adjustments.update', $adjustment), [
                'adjustment_date' => '2026-05-08',
                'reason' => 'Stock opname ulang',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'counted_stock' => 12,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertRedirect();

        $item = $adjustment->refresh()->items()->firstOrFail();
        $this->assertSame('10.000', $item->system_stock);
        $this->assertSame('12.000', $item->counted_stock);
        $this->assertSame('2.000', $item->difference);
        $this->assertSame('10.000', $batch->refresh()->current_stock);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_super_admin_approves_adjustment_and_creates_in_out_movements_only_for_non_zero_difference(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $batchIn = MedicineBatch::factory()->create([
            'purchase_price' => '1000.00',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);
        $batchOut = MedicineBatch::factory()->create([
            'purchase_price' => '2000.00',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);
        $batchZero = MedicineBatch::factory()->create([
            'purchase_price' => '3000.00',
            'current_stock' => '4.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-adjustments.store'), [
                'adjustment_date' => '2026-05-08',
                'reason' => 'Stock opname besar',
                'items' => [
                    [
                        'medicine_batch_id' => $batchIn->id,
                        'counted_stock' => 7,
                        'notes' => null,
                    ],
                    [
                        'medicine_batch_id' => $batchOut->id,
                        'counted_stock' => 3,
                        'notes' => null,
                    ],
                    [
                        'medicine_batch_id' => $batchZero->id,
                        'counted_stock' => 4,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertRedirect();

        $adjustment = StockAdjustment::query()->firstOrFail();

        $this
            ->actingAs($superAdmin)
            ->post(route('stock-adjustments.approve', $adjustment))
            ->assertRedirect();

        $adjustment->refresh();
        $batchIn->refresh();
        $batchOut->refresh();
        $batchZero->refresh();

        $this->assertSame('approved', $adjustment->status->value);
        $this->assertSame($superAdmin->id, $adjustment->approved_by);
        $this->assertSame('7.000', $batchIn->current_stock);
        $this->assertSame('3.000', $batchOut->current_stock);
        $this->assertSame('4.000', $batchZero->current_stock);

        $this->assertSame(1, StockMovement::query()->where('movement_type', 'adjustment_in')->where('reference_id', $adjustment->id)->count());
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'adjustment_out')->where('reference_id', $adjustment->id)->count());
        $this->assertSame(2, StockMovement::query()->where('reference_type', 'stock_adjustments')->where('reference_id', $adjustment->id)->count());

        $inMovement = StockMovement::query()->where('movement_type', 'adjustment_in')->firstOrFail();
        $this->assertSame($batchIn->id, $inMovement->medicine_batch_id);
        $this->assertSame('2.000', $inMovement->quantity_in);
        $this->assertSame('5.000', $inMovement->stock_before);
        $this->assertSame('7.000', $inMovement->stock_after);

        $outMovement = StockMovement::query()->where('movement_type', 'adjustment_out')->firstOrFail();
        $this->assertSame($batchOut->id, $outMovement->medicine_batch_id);
        $this->assertSame('2.000', $outMovement->quantity_out);
        $this->assertSame('5.000', $outMovement->stock_before);
        $this->assertSame('3.000', $outMovement->stock_after);

        $payload = [
            'adjustment_date' => '2026-05-08',
            'reason' => 'Tidak boleh edit',
            'items' => [
                [
                    'medicine_batch_id' => $batchIn->id,
                    'counted_stock' => 5,
                    'notes' => null,
                ],
            ],
        ];

        $this->actingAs($admin)->patch(route('stock-adjustments.update', $adjustment), $payload)->assertSessionHasErrors('status');
        $this->actingAs($superAdmin)->post(route('stock-adjustments.approve', $adjustment))->assertSessionHasErrors('status');
    }

    public function test_super_admin_can_cancel_approved_adjustment_and_reverse_stock_movements(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $batchIn = MedicineBatch::factory()->create([
            'current_stock' => '5.000',
            'status' => 'available',
        ]);
        $batchOut = MedicineBatch::factory()->create([
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-adjustments.store'), [
                'adjustment_date' => '2026-05-08',
                'reason' => 'Stock opname cancel',
                'items' => [
                    [
                        'medicine_batch_id' => $batchIn->id,
                        'counted_stock' => 8,
                        'notes' => null,
                    ],
                    [
                        'medicine_batch_id' => $batchOut->id,
                        'counted_stock' => 3,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertRedirect();

        $adjustment = StockAdjustment::query()->firstOrFail();
        $this->actingAs($superAdmin)->post(route('stock-adjustments.approve', $adjustment))->assertRedirect();

        $this
            ->actingAs($superAdmin)
            ->post(route('stock-adjustments.cancel', $adjustment), [
                'cancel_reason' => 'Salah hitung opname',
            ])
            ->assertRedirect();

        $adjustment->refresh();
        $batchIn->refresh();
        $batchOut->refresh();

        $this->assertSame('cancelled', $adjustment->status->value);
        $this->assertStringContainsString('Pembatalan: Salah hitung opname', $adjustment->reason);
        $this->assertSame('5.000', $batchIn->current_stock);
        $this->assertSame('5.000', $batchOut->current_stock);
        $this->assertSame(2, StockMovement::query()->where('movement_type', 'cancel_adjustment')->where('reference_id', $adjustment->id)->count());

        $this
            ->actingAs($superAdmin)
            ->post(route('stock-adjustments.cancel', $adjustment), [
                'cancel_reason' => 'Cancel ulang',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_stock_adjustment_validates_reason_items_and_counted_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'current_stock' => '1.000',
        ]);

        $this
            ->actingAs($admin)
            ->post(route('stock-adjustments.store'), [
                'adjustment_date' => '2026-05-08',
                'reason' => '',
                'items' => [],
            ])
            ->assertSessionHasErrors(['reason', 'items']);

        $this
            ->actingAs($admin)
            ->post(route('stock-adjustments.store'), [
                'adjustment_date' => '2026-05-08',
                'reason' => 'Stock opname',
                'items' => [
                    [
                        'medicine_batch_id' => $batch->id,
                        'counted_stock' => -1,
                        'notes' => null,
                    ],
                ],
            ])
            ->assertSessionHasErrors('items.0.counted_stock');

        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }
}
