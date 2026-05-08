<?php

namespace Tests\Feature;

use App\Models\StockAdjustment;
use App\Models\StockUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase4AuthRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_users_cannot_login(): void
    {
        $user = User::factory()->inactive()->create([
            'email' => 'inactive@example.test',
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_authenticated_users_are_logged_out(): void
    {
        $user = User::factory()->inactive()->create();

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_internal_routes_require_authentication(): void
    {
        foreach ([
            'dashboard',
            'profile.edit',
            'medicines.index',
            'sales.index',
            'sales.my-history',
            'stock.summary',
            'references.index',
            'suppliers.index',
            'purchase-orders.index',
            'stock-usages.index',
            'stock-adjustments.index',
            'stock-movements.index',
            'reports.index',
            'users.index',
            'settings.index',
        ] as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('login'));
        }
    }

    public function test_employee_can_only_access_employee_features(): void
    {
        $employee = User::factory()->create();

        foreach ([
            'dashboard',
            'medicines.index',
            'sales.index',
            'sales.my-history',
            'stock.summary',
        ] as $routeName) {
            $this->actingAs($employee)->get(route($routeName))->assertOk();
        }

        foreach ([
            'references.index',
            'suppliers.index',
            'purchase-orders.index',
            'stock-usages.index',
            'stock-adjustments.index',
            'stock-movements.index',
            'reports.index',
            'users.index',
            'settings.index',
        ] as $routeName) {
            $this->actingAs($employee)->get(route($routeName))->assertForbidden();
        }
    }

    public function test_admin_cannot_access_super_admin_only_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $stockUsage = StockUsage::factory()->create([
            'created_by' => $admin->id,
            'completed_by' => $admin->id,
            'status' => 'completed',
        ]);
        $stockAdjustment = StockAdjustment::factory()->create([
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
            'status' => 'approved',
        ]);

        $this->actingAs($admin)->get(route('users.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('users.store'))->assertForbidden();
        $this->actingAs($admin)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($admin)->patch(route('settings.update'))->assertForbidden();
        $this->actingAs($admin)->post(route('stock-usages.cancel', $stockUsage))->assertForbidden();
        $this->actingAs($admin)->post(route('stock-adjustments.approve', $stockAdjustment))->assertForbidden();
        $this->actingAs($admin)->post(route('stock-adjustments.cancel', $stockAdjustment))->assertForbidden();
        $this->actingAs($admin)->get(route('reports.index', ['jenis_laporan' => 'simple_margin']))->assertForbidden();
    }

    public function test_admin_can_access_operational_features_and_allowed_reports(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            'dashboard',
            'references.index',
            'suppliers.index',
            'medicines.index',
            'purchase-orders.index',
            'sales.index',
            'stock.summary',
            'stock-usages.index',
            'stock-adjustments.index',
            'stock-movements.index',
        ] as $routeName) {
            $this->actingAs($admin)->get(route($routeName))->assertOk();
        }

        foreach ([
            'stock',
            'low_stock',
            'out_of_stock',
            'expiry',
            'purchase',
            'sales',
            'stock_movement',
        ] as $reportType) {
            $this
                ->actingAs($admin)
                ->get(route('reports.index', ['jenis_laporan' => $reportType]))
                ->assertOk();
        }
    }

    public function test_super_admin_can_access_super_admin_only_actions_and_all_reports(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $stockUsage = StockUsage::factory()->create();
        $stockAdjustment = StockAdjustment::factory()->create();

        foreach ([
            'dashboard',
            'references.index',
            'suppliers.index',
            'medicines.index',
            'purchase-orders.index',
            'sales.index',
            'stock.summary',
            'stock-usages.index',
            'stock-adjustments.index',
            'stock-movements.index',
            'reports.index',
            'users.index',
            'settings.index',
        ] as $routeName) {
            $this->actingAs($superAdmin)->get(route($routeName))->assertOk();
        }

        $this->actingAs($superAdmin)->post(route('users.store'))->assertStatus(302);
        $this->actingAs($superAdmin)->patch(route('settings.update'))->assertStatus(302);
        $this->actingAs($superAdmin)->post(route('stock-usages.cancel', $stockUsage))->assertStatus(302);
        $this->actingAs($superAdmin)->post(route('stock-adjustments.approve', $stockAdjustment))->assertStatus(302);
        $this->actingAs($superAdmin)->post(route('stock-adjustments.cancel', $stockAdjustment))->assertStatus(302);
        $this->actingAs($superAdmin)->get(route('reports.index', ['jenis_laporan' => 'simple_margin']))->assertOk();
    }

    public function test_shared_navigation_is_filtered_by_role(): void
    {
        $employee = User::factory()->create();

        $this
            ->actingAs($employee)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('navigation', 5)
                ->where('navigation.0.route', 'dashboard')
                ->where('navigation.1.route', 'medicines.index')
                ->where('navigation.2.route', 'sales.index')
                ->where('navigation.3.route', 'sales.my-history')
                ->where('navigation.4.route', 'stock.summary')
            );
    }
}
