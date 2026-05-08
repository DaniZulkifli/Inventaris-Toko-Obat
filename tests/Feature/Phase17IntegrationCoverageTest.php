<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase17IntegrationCoverageTest extends TestCase
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

    public function test_sales_are_completed_only_even_when_client_submits_status_fields(): void
    {
        $cashier = User::factory()->create();
        $medicine = Medicine::factory()->create([
            'selling_price' => '1500.00',
            'is_active' => true,
        ]);
        $batch = MedicineBatch::factory()->for($medicine)->create([
            'expiry_date' => '2026-08-31',
            'purchase_price' => '700.00',
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($cashier)
            ->post(route('sales.store'), [
                'status' => 'draft',
                'refund_amount' => 999999,
                'payment_method' => 'cash',
                'discount' => 0,
                'amount_paid' => 3000,
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
        $this->assertSame('completed', $sale->status->value);
        $this->assertSame('3000.00', $sale->total_amount);
        $this->assertSame(0, Sale::query()->whereIn('status', ['draft', 'cancelled'])->count());
        $this->assertFalse(Route::has('sales.update'));
        $this->assertFalse(Route::has('sales.destroy'));
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'sale_out')->where('reference_id', $sale->id)->count());
    }

    public function test_report_integration_uses_one_page_and_export_endpoint_with_active_filters(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $this->assertTrue(Route::has('reports.index'));
        $this->assertTrue(Route::has('reports.export'));
        $this->assertFalse(Route::has('reports.sales'));
        $this->assertFalse(Route::has('reports.stock'));

        $this
            ->actingAs($admin)
            ->get(route('reports.index', [
                'jenis_laporan' => 'sales',
                'date_from' => '2026-05-05',
                'date_to' => '2026-05-07',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('filters.jenis_laporan', 'sales')
                ->where('filters.date_from', '2026-05-05')
                ->where('filters.date_to', '2026-05-07')
                ->has('report.rows.data')
            );

        $this
            ->actingAs($admin)
            ->get(route('reports.export', [
                'jenis_laporan' => 'sales',
                'date_from' => '2026-05-05',
                'date_to' => '2026-05-07',
                'format' => 'xlsx',
            ]))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="laporan-sales-2026-05-05-2026-05-07.xlsx"');
    }
}
