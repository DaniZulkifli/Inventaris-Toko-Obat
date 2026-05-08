<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase7DashboardTest extends TestCase
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

    public function test_super_admin_dashboard_contains_owner_summary(): void
    {
        $superAdmin = User::query()->where('email', 'superadmin@tokoobat.test')->firstOrFail();

        $this
            ->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('dashboard.role', 'super_admin')
                ->where('dashboard.cards.0.key', 'total_active_medicines')
                ->where('dashboard.cards.0.value', 20)
                ->where('dashboard.cards.1.key', 'low_stock')
                ->where('dashboard.cards.1.value', 4)
                ->where('dashboard.cards.2.key', 'out_of_stock')
                ->where('dashboard.cards.2.value', 2)
                ->where('dashboard.cards.3.key', 'near_expiry')
                ->where('dashboard.cards.3.value', 7)
                ->where('dashboard.cards.4.key', 'expired_batches')
                ->where('dashboard.cards.4.value', 2)
                ->where('dashboard.cards.5.key', 'sales_today')
                ->where('dashboard.cards.5.value', 0)
                ->where('dashboard.cards.6.key', 'purchases_this_month')
                ->where('dashboard.cards.6.value', 3)
                ->has('dashboard.sections.latest_activity', 8)
                ->has('dashboard.sections.near_expiry_batches', 7)
            );
    }

    public function test_admin_dashboard_contains_operational_sections(): void
    {
        $admin = User::query()->where('email', 'admin@tokoobat.test')->firstOrFail();

        $this
            ->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('dashboard.role', 'admin')
                ->where('dashboard.sections.stock_summary.low_stock', 4)
                ->where('dashboard.sections.stock_summary.out_of_stock', 2)
                ->has('dashboard.sections.latest_purchase_orders', 5)
                ->has('dashboard.sections.latest_stock_usages', 3)
                ->has('dashboard.sections.draft_adjustments', 1)
                ->where('dashboard.sections.draft_adjustments.0.code', 'ADJ-20260508-0001')
            );
    }

    public function test_employee_dashboard_is_scoped_to_employee_needs(): void
    {
        $employee = User::query()->where('email', 'maya@tokoobat.test')->firstOrFail();

        $this
            ->actingAs($employee)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('dashboard.role', 'employee')
                ->where('dashboard.cards.0.key', 'low_stock')
                ->where('dashboard.cards.0.value', 6)
                ->where('dashboard.cards.1.key', 'sales_today_by_user')
                ->where('dashboard.cards.1.value', 0)
                ->has('dashboard.sections.important_stock', 6)
                ->has('dashboard.sections.near_expiry_batches', 7)
                ->missing('dashboard.sections.latest_activity')
                ->missing('dashboard.sections.latest_purchase_orders')
            );
    }
}
