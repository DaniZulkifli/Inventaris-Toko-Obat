<?php

namespace Tests\Feature;

use App\Enums\MedicineBatchStatus;
use App\Enums\MedicineClassification;
use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SaleStatus;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockUsageStatus;
use App\Enums\StockUsageType;
use App\Enums\UserRole;
use App\Models\MedicineBatch;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\StockUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Phase2SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_tables_exist_with_key_columns(): void
    {
        foreach ([
            'medicine_categories',
            'units',
            'dosage_forms',
            'suppliers',
            'medicines',
            'medicine_batches',
            'purchase_orders',
            'purchase_order_items',
            'sales',
            'sale_items',
            'stock_usages',
            'stock_usage_items',
            'stock_adjustments',
            'stock_adjustment_items',
            'stock_movements',
            'activity_logs',
            'settings',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} table is missing.");
        }

        $this->assertTrue(Schema::hasColumns('medicine_batches', [
            'medicine_id',
            'batch_number',
            'expiry_date',
            'current_stock',
            'status',
        ]));

        $this->assertTrue(Schema::hasColumns('stock_movements', [
            'medicine_id',
            'medicine_batch_id',
            'movement_type',
            'quantity_in',
            'quantity_out',
            'stock_before',
            'stock_after',
        ]));
    }

    public function test_enums_match_prd_values(): void
    {
        $this->assertSame(['super_admin', 'admin', 'employee'], array_column(UserRole::cases(), 'value'));
        $this->assertSame(['draft', 'received'], array_column(PurchaseOrderStatus::cases(), 'value'));
        $this->assertSame(['completed'], array_column(SaleStatus::cases(), 'value'));
        $this->assertSame(['available', 'expired', 'depleted', 'quarantined'], array_column(MedicineBatchStatus::cases(), 'value'));
        $this->assertSame(['damaged', 'expired', 'lost', 'sample', 'return_supplier', 'internal_use', 'other'], array_column(StockUsageType::cases(), 'value'));
        $this->assertSame(['draft', 'completed', 'cancelled'], array_column(StockUsageStatus::cases(), 'value'));
        $this->assertSame(['draft', 'approved', 'cancelled'], array_column(StockAdjustmentStatus::cases(), 'value'));
        $this->assertSame(['opening_stock', 'purchase_in', 'sale_out', 'usage_out', 'adjustment_in', 'adjustment_out', 'cancel_usage', 'cancel_adjustment'], array_column(MovementType::cases(), 'value'));
        $this->assertSame(['cash', 'transfer', 'qris', 'other'], array_column(PaymentMethod::cases(), 'value'));
        $this->assertSame(['obat_bebas', 'obat_bebas_terbatas', 'obat_keras', 'vitamin_suplemen', 'alkes', 'other'], array_column(MedicineClassification::cases(), 'value'));
    }

    public function test_core_factories_create_related_models(): void
    {
        $user = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->for($user, 'creator')->create();
        $sale = Sale::factory()->for($user, 'cashier')->create();
        $usage = StockUsage::factory()->for($user, 'creator')->create();
        $adjustment = StockAdjustment::factory()->for($user, 'creator')->create();

        $this->assertTrue($batch->medicine->category()->exists());
        $this->assertTrue($batch->supplier()->exists());
        $this->assertTrue($purchaseOrder->supplier()->exists());
        $this->assertTrue($sale->cashier()->is($user));
        $this->assertTrue($usage->creator()->is($user));
        $this->assertTrue($adjustment->creator()->is($user));
    }
}
