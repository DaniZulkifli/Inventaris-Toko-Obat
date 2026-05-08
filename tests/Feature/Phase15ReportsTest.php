<?php

namespace Tests\Feature;

use App\Models\MedicineBatch;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase15ReportsTest extends TestCase
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

    public function test_reports_page_is_single_page_with_stock_report_pagination(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $this
            ->actingAs($admin)
            ->get(route('reports.index', ['jenis_laporan' => 'stock']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('filters.jenis_laporan', 'stock')
                ->where('report.type', 'stock')
                ->has('options.report_types', 7)
                ->has('report.columns')
                ->has('report.rows.data', 15)
                ->where('report.rows.total', 30)
            );
    }

    public function test_sales_report_uses_completed_sales_in_requested_date_range(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();
        $expectedRows = SaleItem::query()
            ->whereHas('sale', function ($query): void {
                $query
                    ->where('status', 'completed')
                    ->whereDate('sale_date', '>=', '2026-05-05')
                    ->whereDate('sale_date', '<=', '2026-05-07');
            })
            ->count();

        $response = $this
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
                ->where('report.rows.total', $expectedRows)
                ->where('report.summary.0.value', $expectedRows)
            );

        collect($response->viewData('page')['props']['report']['rows']['data'])->each(function (array $row): void {
            $this->assertContains($row['payment_method'], ['cash', 'transfer', 'qris', 'other']);
            $this->assertTrue(Carbon::parse($row['sale_date'])->betweenIncluded(
                Carbon::parse('2026-05-05')->startOfDay(),
                Carbon::parse('2026-05-07')->endOfDay()
            ));
        });
    }

    public function test_expiry_report_range_matches_seed_near_expiry_batches(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $response = $this
            ->actingAs($admin)
            ->get(route('reports.index', [
                'jenis_laporan' => 'expiry',
                'expiry_from' => '2026-05-09',
                'expiry_to' => '2026-07-07',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('filters.jenis_laporan', 'expiry')
                ->where('filters.expiry_from', '2026-05-09')
                ->where('filters.expiry_to', '2026-07-07')
                ->where('report.rows.total', 7)
            );

        $batchNumbers = collect($response->viewData('page')['props']['report']['rows']['data'])
            ->pluck('batch_number')
            ->all();

        $this->assertEqualsCanonicalizing([
            'BAT-PCT-2501',
            'BAT-AMX-2506',
            'BAT-OBH-2505',
            'BAT-AML-2606',
            'BAT-HDC-2506',
            'BAT-LRT-2606',
            'BAT-MIC-2606',
        ], $batchNumbers);
    }

    public function test_report_access_follows_role_rules(): void
    {
        $employee = User::query()->where('email', 'maya@tokoobat.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();
        $superAdmin = User::query()->where('email', 'superadmin@tokoobat.test')->firstOrFail();

        $this->actingAs($employee)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('reports.index', ['jenis_laporan' => 'supplier']))->assertForbidden();
        $this->actingAs($admin)->get(route('reports.index', ['jenis_laporan' => 'simple_margin']))->assertForbidden();

        $this
            ->actingAs($superAdmin)
            ->get(route('reports.index', [
                'jenis_laporan' => 'simple_margin',
                'date_from' => '2026-05-05',
                'date_to' => '2026-05-07',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('filters.jenis_laporan', 'simple_margin')
                ->has('options.report_types', 9)
            );
    }

    public function test_report_export_downloads_pdf_and_excel_using_active_filters(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $xlsxResponse = $this
            ->actingAs($admin)
            ->get(route('reports.export', [
                'jenis_laporan' => 'sales',
                'date_from' => '2026-05-05',
                'date_to' => '2026-05-07',
                'format' => 'xlsx',
            ]))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="laporan-sales-2026-05-05-2026-05-07.xlsx"');

        $this->assertStringStartsWith('PK', $xlsxResponse->getContent());

        $pdfResponse = $this
            ->actingAs($admin)
            ->get(route('reports.export', [
                'jenis_laporan' => 'expiry',
                'expiry_from' => '2026-05-09',
                'expiry_to' => '2026-07-07',
                'format' => 'pdf',
            ]))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="laporan-expiry-2026-05-09-2026-07-07.pdf"');

        $this->assertStringStartsWith('%PDF-1.4', $pdfResponse->getContent());
    }

    public function test_stock_and_expiry_seed_baselines_are_reportable(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $this->assertSame(30, MedicineBatch::query()->count());

        $this
            ->actingAs($admin)
            ->get(route('reports.index', [
                'jenis_laporan' => 'low_stock',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('report.rows.total', 4)
            );
    }
}
