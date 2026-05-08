<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase16SecurityAuditTest extends TestCase
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

    public function test_medicine_image_upload_is_limited_to_safe_image_files(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $category = MedicineCategory::factory()->create();
        $unit = Unit::factory()->create();

        $this
            ->actingAs($admin)
            ->post(route('medicines.store'), $this->medicinePayload([
                'medicine_category_id' => $category->id,
                'unit_id' => $unit->id,
                'image_path' => UploadedFile::fake()->image('obat.png')->size(512),
            ]))
            ->assertRedirect(route('medicines.index'));

        $medicine = Medicine::query()->where('name', 'Obat Security')->firstOrFail();
        $this->assertStringStartsWith('medicine-images/', $medicine->image_path);
        Storage::disk('public')->assertExists($medicine->image_path);

        $this
            ->actingAs($admin)
            ->post(route('medicines.store'), $this->medicinePayload([
                'medicine_category_id' => $category->id,
                'unit_id' => $unit->id,
                'name' => 'Obat File Salah',
                'barcode' => 'SEC-2',
                'image_path' => UploadedFile::fake()->create('script.txt', 10, 'text/plain'),
            ]))
            ->assertSessionHasErrors('image_path');

        $this
            ->actingAs($admin)
            ->post(route('medicines.store'), $this->medicinePayload([
                'medicine_category_id' => $category->id,
                'unit_id' => $unit->id,
                'name' => 'Obat File Besar',
                'barcode' => 'SEC-3',
                'image_path' => UploadedFile::fake()->image('besar.jpg')->size(2049),
            ]))
            ->assertSessionHasErrors('image_path');
    }

    public function test_sensitive_settings_and_upload_limit_are_rejected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this
            ->actingAs($superAdmin)
            ->patch(route('settings.update'), [
                'settings' => [
                    'store_name' => 'Toko Aman',
                    'store_address' => 'Jl Test',
                    'store_phone' => '0812',
                    'timezone' => 'Asia/Makassar',
                    'default_minimum_stock' => '5',
                    'expiry_warning_days' => '60',
                    'pagination_per_page' => '20',
                    'report_export_formats' => 'pdf,xlsx',
                    'upload_max_file_size_mb' => '3',
                    'theme_primary_color' => '#059669',
                    'api_key' => 'secret-value',
                ],
            ])
            ->assertSessionHasErrors(['settings.upload_max_file_size_mb', 'settings']);
    }

    public function test_repeated_failed_login_attempts_are_audited(): void
    {
        User::factory()->create([
            'email' => 'audit-login@example.test',
            'password' => 'password',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('login'), [
                'email' => 'audit-login@example.test',
                'password' => 'salah',
            ])->assertSessionHasErrors('email');
        }

        $log = ActivityLog::query()
            ->where('action', 'login_failed')
            ->where('module', 'auth')
            ->firstOrFail();

        $this->assertStringContainsString('Login gagal berulang', $log->description);
        $this->assertStringContainsString('audit-login@example.test', $log->description);
    }

    public function test_price_active_status_and_batch_status_changes_are_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $medicine = Medicine::factory()->create([
            'default_purchase_price' => '1000.00',
            'selling_price' => '1500.00',
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->patch(route('medicines.update', $medicine), $this->medicinePayload([
                'medicine_category_id' => $medicine->medicine_category_id,
                'unit_id' => $medicine->unit_id,
                'dosage_form_id' => $medicine->dosage_form_id,
                'code' => $medicine->code,
                'barcode' => $medicine->barcode,
                'name' => $medicine->name,
                'default_purchase_price' => 1200,
                'selling_price' => 1800,
                'is_active' => false,
                'image_path' => null,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'change_price',
            'module' => 'medicines',
            'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'deactivate',
            'module' => 'medicines',
            'user_id' => $admin->id,
        ]);

        $batch = MedicineBatch::factory()->for($medicine)->create([
            'current_stock' => '5.000',
            'status' => 'available',
        ]);

        $this
            ->actingAs($admin)
            ->patch(route('medicine-batches.update', $batch), [
                'medicine_id' => $batch->medicine_id,
                'supplier_id' => $batch->supplier_id,
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'purchase_price' => $batch->purchase_price,
                'selling_price' => $batch->selling_price,
                'initial_stock' => null,
                'received_date' => $batch->received_date?->toDateString(),
                'status' => 'quarantined',
                'notes' => $batch->notes,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'change_status',
            'module' => 'medicine_batches',
            'user_id' => $admin->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function medicinePayload(array $overrides = []): array
    {
        return [
            'medicine_category_id' => $overrides['medicine_category_id'] ?? MedicineCategory::factory()->create()->id,
            'unit_id' => $overrides['unit_id'] ?? Unit::factory()->create()->id,
            'dosage_form_id' => $overrides['dosage_form_id'] ?? null,
            'code' => $overrides['code'] ?? '',
            'barcode' => $overrides['barcode'] ?? 'SEC-1',
            'name' => $overrides['name'] ?? 'Obat Security',
            'generic_name' => $overrides['generic_name'] ?? null,
            'manufacturer' => $overrides['manufacturer'] ?? null,
            'registration_number' => $overrides['registration_number'] ?? null,
            'active_ingredient' => $overrides['active_ingredient'] ?? null,
            'strength' => $overrides['strength'] ?? null,
            'classification' => $overrides['classification'] ?? 'obat_bebas',
            'requires_prescription' => $overrides['requires_prescription'] ?? false,
            'default_purchase_price' => $overrides['default_purchase_price'] ?? 1000,
            'selling_price' => $overrides['selling_price'] ?? 1500,
            'minimum_stock' => $overrides['minimum_stock'] ?? 5,
            'reorder_level' => $overrides['reorder_level'] ?? 10,
            'storage_instruction' => $overrides['storage_instruction'] ?? null,
            'image_path' => $overrides['image_path'] ?? null,
            'is_active' => $overrides['is_active'] ?? true,
        ];
    }
}
