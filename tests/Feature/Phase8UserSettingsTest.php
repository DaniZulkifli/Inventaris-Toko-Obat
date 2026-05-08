<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase8UserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_user_management(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        User::factory()->admin()->create();
        User::factory()->inactive()->create();

        $this
            ->actingAs($superAdmin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->where('stats.total', 3)
                ->where('stats.active', 2)
                ->where('stats.inactive', 1)
                ->has('users.data', 3)
            );
    }

    public function test_super_admin_can_create_update_deactivate_and_delete_user_without_operational_data(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this
            ->actingAs($superAdmin)
            ->post(route('users.store'), [
                'name' => 'Kasir Baru',
                'email' => 'kasir@example.test',
                'phone' => '0812345678',
                'role' => 'employee',
                'is_active' => true,
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertRedirect(route('users.index'));

        $user = User::query()->where('email', 'kasir@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertNotNull($user->email_verified_at);

        $this
            ->actingAs($superAdmin)
            ->patch(route('users.update', $user), [
                'name' => 'Kasir Update',
                'email' => 'kasir-update@example.test',
                'phone' => '0899999999',
                'role' => 'admin',
                'is_active' => false,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect();

        $user->refresh();
        $this->assertSame('Kasir Update', $user->name);
        $this->assertSame('admin', $user->role->value);
        $this->assertFalse($user->is_active);

        $this
            ->actingAs($superAdmin)
            ->delete(route('users.destroy', $user))
            ->assertRedirect();

        $this->assertNull($user->fresh());
    }

    public function test_user_with_operational_data_cannot_be_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $cashier = User::factory()->create();
        Sale::factory()->for($cashier, 'cashier')->create();

        $this
            ->actingAs($superAdmin)
            ->delete(route('users.destroy', $cashier))
            ->assertSessionHasErrors('user');

        $this->assertNotNull($cashier->fresh());
    }

    public function test_last_active_super_admin_cannot_be_deactivated_or_deleted(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this
            ->actingAs($superAdmin)
            ->patch(route('users.update', $superAdmin), [
                'name' => $superAdmin->name,
                'email' => $superAdmin->email,
                'phone' => $superAdmin->phone,
                'role' => 'super_admin',
                'is_active' => false,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertSessionHasErrors('is_active');

        $this
            ->actingAs($superAdmin)
            ->delete(route('users.destroy', $superAdmin))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_super_admin_can_open_and_update_allowed_settings(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $payload = [
            'settings' => [
                'store_name' => 'Toko Obat Baru',
                'store_address' => 'Jl. Baru No. 1',
                'store_phone' => '0811111111',
                'timezone' => 'Asia/Makassar',
                'default_minimum_stock' => '15',
                'expiry_warning_days' => '45',
                'pagination_per_page' => '25',
                'report_export_formats' => 'pdf,xlsx',
                'upload_max_file_size_mb' => '2',
                'theme_primary_color' => '#16a34a',
            ],
        ];

        $this
            ->actingAs($superAdmin)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Index')
                ->has('settings.store_name')
                ->has('settings.theme_primary_color')
            );

        $this
            ->actingAs($superAdmin)
            ->patch(route('settings.update'), $payload)
            ->assertRedirect(route('settings.index'));

        $this->assertSame('Toko Obat Baru', Setting::query()->where('key', 'store_name')->value('value'));
        $this->assertSame('45', Setting::query()->where('key', 'expiry_warning_days')->value('value'));
    }

    public function test_settings_reject_unknown_or_sensitive_keys(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this
            ->actingAs($superAdmin)
            ->patch(route('settings.update'), [
                'settings' => [
                    'store_name' => 'Toko Obat Baru',
                    'store_address' => 'Jl. Baru No. 1',
                    'store_phone' => '0811111111',
                    'timezone' => 'Asia/Makassar',
                    'default_minimum_stock' => '15',
                    'expiry_warning_days' => '45',
                    'pagination_per_page' => '25',
                    'report_export_formats' => 'pdf,xlsx',
                    'upload_max_file_size_mb' => '2',
                    'theme_primary_color' => '#16a34a',
                    'api_key' => 'secret',
                ],
            ])
            ->assertSessionHasErrors('settings');

        $this->assertFalse(Setting::query()->where('key', 'api_key')->exists());
    }

    public function test_admin_receives_non_sensitive_store_settings_but_cannot_open_settings_page(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::query()->create([
            'key' => 'store_name',
            'value' => 'Toko Obat Sehat',
            'type' => 'string',
            'description' => 'Nama toko',
        ]);

        $this
            ->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.store_name', 'Toko Obat Sehat')
                ->has('store.store_address')
                ->has('store.timezone')
                ->missing('store.api_key')
            );

        $this
            ->actingAs($admin)
            ->get(route('settings.index'))
            ->assertForbidden();
    }
}
