<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase9MasterDataTest extends TestCase
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

    public function test_reference_page_and_crud_rules_work(): void
    {
        $admin = User::factory()->admin()->create();

        $this
            ->actingAs($admin)
            ->get(route('references.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('References/Index')
                ->has('references.categories')
                ->has('references.units')
                ->has('references.dosage_forms')
            );

        $this
            ->actingAs($admin)
            ->post(route('references.store', 'categories'), [
                'name' => 'Kategori Baru',
                'description' => 'Testing',
            ])
            ->assertRedirect(route('references.index'));

        $category = MedicineCategory::query()->where('name', 'Kategori Baru')->firstOrFail();
        Medicine::factory()->for($category, 'category')->create();

        $this
            ->actingAs($admin)
            ->delete(route('references.destroy', ['categories', $category->id]))
            ->assertSessionHasErrors('reference');
    }

    public function test_supplier_crud_and_delete_guard(): void
    {
        $admin = User::factory()->admin()->create();

        $this
            ->actingAs($admin)
            ->post(route('suppliers.store'), [
                'name' => 'Supplier Test',
                'phone' => '0812',
                'email' => 'supplier@example.test',
                'address' => 'Jl Test',
                'contact_person' => 'Rani',
                'notes' => 'Aktif',
                'is_active' => true,
            ])
            ->assertRedirect(route('suppliers.index'));

        $supplier = Supplier::query()->where('name', 'Supplier Test')->firstOrFail();

        $this
            ->actingAs($admin)
            ->patch(route('suppliers.update', $supplier), [
                'name' => 'Supplier Test Nonaktif',
                'phone' => '0812',
                'email' => 'supplier@example.test',
                'address' => 'Jl Test',
                'contact_person' => 'Rani',
                'notes' => 'Nonaktif',
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($supplier->refresh()->is_active);

        MedicineBatch::factory()->for($supplier)->create();

        $this
            ->actingAs($admin)
            ->delete(route('suppliers.destroy', $supplier))
            ->assertSessionHasErrors('supplier');
    }

    public function test_medicine_code_is_generated_and_used_medicine_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $category = MedicineCategory::factory()->create();
        $unit = Unit::factory()->create();

        $this
            ->actingAs($admin)
            ->post(route('medicines.store'), [
                'medicine_category_id' => $category->id,
                'unit_id' => $unit->id,
                'dosage_form_id' => null,
                'code' => '',
                'barcode' => '1234567890123',
                'name' => 'Obat Auto Code',
                'generic_name' => null,
                'manufacturer' => null,
                'registration_number' => null,
                'active_ingredient' => null,
                'strength' => null,
                'classification' => 'obat_bebas',
                'requires_prescription' => false,
                'default_purchase_price' => 1000,
                'selling_price' => 1500,
                'minimum_stock' => 5,
                'reorder_level' => 10,
                'storage_instruction' => null,
                'image_path' => null,
                'is_active' => true,
            ])
            ->assertRedirect(route('medicines.index'));

        $medicine = Medicine::query()->where('name', 'Obat Auto Code')->firstOrFail();
        $this->assertStringStartsWith('MED-20260508-', $medicine->code);

        MedicineBatch::factory()->for($medicine)->create();

        $this
            ->actingAs($admin)
            ->delete(route('medicines.destroy', $medicine))
            ->assertSessionHasErrors('medicine');
    }

    public function test_batch_rules_auto_number_opening_stock_and_expiry_validation(): void
    {
        $admin = User::factory()->admin()->create();
        $regularMedicine = Medicine::factory()->create([
            'classification' => 'obat_bebas',
        ]);
        $alkes = Medicine::factory()->create([
            'classification' => 'alkes',
        ]);
        $supplier = Supplier::factory()->create([
            'is_active' => true,
        ]);

        $this
            ->actingAs($admin)
            ->post(route('medicine-batches.store'), [
                'medicine_id' => $regularMedicine->id,
                'supplier_id' => $supplier->id,
                'batch_number' => '',
                'expiry_date' => '',
                'purchase_price' => 1000,
                'selling_price' => null,
                'initial_stock' => 5,
                'received_date' => '2026-05-08',
                'status' => 'available',
                'notes' => null,
            ])
            ->assertSessionHasErrors('expiry_date');

        $this
            ->actingAs($admin)
            ->post(route('medicine-batches.store'), [
                'medicine_id' => $alkes->id,
                'supplier_id' => $supplier->id,
                'batch_number' => '',
                'expiry_date' => '',
                'purchase_price' => 1000,
                'selling_price' => null,
                'initial_stock' => 5,
                'received_date' => '2026-05-08',
                'status' => 'available',
                'notes' => 'Batch alkes tanpa expiry',
            ])
            ->assertRedirect(route('medicines.index', ['tab' => 'batches']));

        $batch = MedicineBatch::query()->where('medicine_id', $alkes->id)->firstOrFail();

        $this->assertStringStartsWith('AUTO-20260508-', $batch->batch_number);
        $this->assertNull($batch->expiry_date);
        $this->assertSame('5.000', $batch->initial_stock);
        $this->assertSame('5.000', $batch->current_stock);

        $movement = StockMovement::query()->where('medicine_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('opening_stock', $movement->movement_type->value);
        $this->assertSame('5.000', $movement->quantity_in);
    }

    public function test_employee_can_read_active_medicines_but_cannot_manage_master_data(): void
    {
        $employee = User::factory()->create();
        Medicine::factory()->create(['is_active' => true]);
        Medicine::factory()->create(['is_active' => false]);

        $this
            ->actingAs($employee)
            ->get(route('medicines.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Medicines/Index')
                ->where('canManage', false)
                ->has('medicines.data', 1)
            );

        $this
            ->actingAs($employee)
            ->post(route('medicines.store'), [])
            ->assertForbidden();
    }
}
