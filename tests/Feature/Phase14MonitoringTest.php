<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase14MonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-08 12:00:00', 'Asia/Makassar'));
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_employee_can_access_stock_monitoring_but_not_stock_movement(): void
    {
        $employee = User::query()->where('email', 'maya@tokoobat.test')->firstOrFail();

        $this
            ->actingAs($employee)
            ->get(route('stock.summary'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Summary')
                ->where('filters.stock_status', 'all')
                ->where('options.expiry_warning_days', 60)
                ->has('medicines.data')
                ->has('batches.data')
            );

        $this->actingAs($employee)->get(route('stock-movements.index'))->assertForbidden();
    }

    public function test_stock_monitoring_low_and_out_of_stock_filters_follow_saleable_stock_rules(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $lowStockResponse = $this
            ->actingAs($admin)
            ->get(route('stock.summary', ['stock_status' => 'low_stock']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Summary')
                ->where('filters.stock_status', 'low_stock')
                ->has('medicines.data', 4)
            );

        $this->assertEqualsCanonicalizing([
            'Amoxicillin 500 mg Kapsul',
            'OBH Herbal 100 ml Sirup',
            'Oralit Sachet',
            'Hydrocortisone Cream 5 g',
        ], $this->medicineNames($lowStockResponse->viewData('page')['props']));

        $outOfStockResponse = $this
            ->actingAs($admin)
            ->get(route('stock.summary', ['stock_status' => 'out_of_stock']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Summary')
                ->where('filters.stock_status', 'out_of_stock')
                ->has('medicines.data', 2)
            );

        $this->assertEqualsCanonicalizing([
            'Simvastatin 20 mg Tablet',
            'Salbutamol 2 mg Tablet',
        ], $this->medicineNames($outOfStockResponse->viewData('page')['props']));
    }

    public function test_batch_monitoring_filters_expired_quarantined_and_near_expiry_ranges(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $expiredResponse = $this
            ->actingAs($admin)
            ->get(route('stock.summary', [
                'tab' => 'batches',
                'batch_status' => 'expired',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Summary')
                ->where('filters.tab', 'batches')
                ->where('filters.batch_status', 'expired')
                ->has('batches.data', 2)
            );

        $this->assertContains('BAT-VTC-2505', $this->batchNumbers($expiredResponse->viewData('page')['props']));

        $quarantinedResponse = $this
            ->actingAs($admin)
            ->get(route('stock.summary', [
                'tab' => 'batches',
                'batch_status' => 'quarantined',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Summary')
                ->where('filters.batch_status', 'quarantined')
                ->has('batches.data', 1)
            );

        $this->assertSame(['BAT-SLB-2608'], $this->batchNumbers($quarantinedResponse->viewData('page')['props']));

        $nearExpiryResponse = $this
            ->actingAs($admin)
            ->get(route('stock.summary', [
                'tab' => 'batches',
                'expiry_from' => '2026-05-09',
                'expiry_to' => '2026-07-07',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stock/Summary')
                ->where('filters.expiry_from', '2026-05-09')
                ->where('filters.expiry_to', '2026-07-07')
                ->has('batches.data', 7)
            );

        $nearExpiryProps = $nearExpiryResponse->viewData('page')['props'];
        $this->assertEqualsCanonicalizing([
            'BAT-PCT-2501',
            'BAT-AMX-2506',
            'BAT-OBH-2505',
            'BAT-AML-2606',
            'BAT-HDC-2506',
            'BAT-LRT-2606',
            'BAT-MIC-2606',
        ], $this->batchNumbers($nearExpiryProps));

        collect($nearExpiryProps['batches']['data'])->each(function (array $batch): void {
            $this->assertSame('near_expiry', $batch['expiry_state']);
            $this->assertNotNull($batch['expiry_date']);
        });
    }

    public function test_admin_stock_movement_page_lists_filtered_movements_with_detail_fields(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $response = $this
            ->actingAs($admin)
            ->get(route('stock-movements.index', [
                'movement_type' => 'purchase_in',
                'reference_type' => 'purchase_orders',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('StockMovements/Index')
                ->where('filters.movement_type', 'purchase_in')
                ->where('filters.reference_type', 'purchase_orders')
                ->has('movements.data', 7)
                ->has('options.movement_types', 8)
                ->has('options.reference_types')
            );

        $movements = collect($response->viewData('page')['props']['movements']['data']);

        $this->assertTrue($movements->every(fn (array $movement): bool => $movement['movement_type'] === 'purchase_in'));
        $this->assertTrue($movements->every(fn (array $movement): bool => $movement['reference_type'] === 'purchase_orders'));

        $firstMovement = $movements->first();
        foreach ([
            'medicine',
            'batch_number',
            'movement_type',
            'reference_label',
            'quantity_in',
            'quantity_out',
            'stock_before',
            'stock_after',
            'unit_cost_snapshot',
            'created_by',
            'created_at',
            'description',
        ] as $field) {
            $this->assertArrayHasKey($field, $firstMovement);
        }
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<int, string>
     */
    private function medicineNames(array $props): array
    {
        return collect($props['medicines']['data'])
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<int, string>
     */
    private function batchNumbers(array $props): array
    {
        return collect($props['batches']['data'])
            ->pluck('batch_number')
            ->all();
    }
}
