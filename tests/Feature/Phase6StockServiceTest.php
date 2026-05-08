<?php

namespace Tests\Feature;

use App\Enums\MedicineBatchStatus;
use App\Enums\MovementType;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase6StockServiceTest extends TestCase
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

    public function test_purchase_in_updates_stock_and_creates_stock_movement(): void
    {
        $user = User::factory()->admin()->create();
        $batch = MedicineBatch::factory()->create([
            'current_stock' => '5.000',
            'initial_stock' => '5.000',
            'expiry_date' => '2026-06-01',
            'status' => MedicineBatchStatus::Available->value,
        ]);

        $movement = app(StockService::class)->purchaseIn($batch, '2.500', $user, [
            'reference_id' => 10,
            'description' => 'PO test',
        ]);

        $batch->refresh();

        $this->assertSame('7.500', $batch->current_stock);
        $this->assertSame(MedicineBatchStatus::Available, $batch->status);
        $this->assertSame(MovementType::PurchaseIn, $movement->movement_type);
        $this->assertSame('2.500', $movement->quantity_in);
        $this->assertSame('0.000', $movement->quantity_out);
        $this->assertSame('5.000', $movement->stock_before);
        $this->assertSame('7.500', $movement->stock_after);
        $this->assertSame('purchase_orders', $movement->reference_type);
        $this->assertSame(10, $movement->reference_id);
    }

    public function test_sale_out_uses_fefo_and_skips_unsaleable_batches(): void
    {
        $user = User::factory()->create();
        $medicine = Medicine::factory()->create([
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        $older = MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'FEFO-OLDER',
            'expiry_date' => '2026-05-20',
            'current_stock' => '5.000',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        $newer = MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'FEFO-NEWER',
            'expiry_date' => '2026-06-20',
            'current_stock' => '10.000',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'FEFO-EXPIRED',
            'expiry_date' => '2026-05-01',
            'current_stock' => '20.000',
            'status' => MedicineBatchStatus::Expired->value,
        ]);
        MedicineBatch::factory()->for($medicine)->create([
            'batch_number' => 'FEFO-QUARANTINED',
            'expiry_date' => '2026-07-01',
            'current_stock' => '20.000',
            'status' => MedicineBatchStatus::Quarantined->value,
        ]);

        $movements = app(StockService::class)->saleOut($medicine, '7.000', $user, [
            'reference_id' => 20,
        ]);

        $this->assertCount(2, $movements);
        $this->assertSame($older->id, $movements[0]->medicine_batch_id);
        $this->assertSame('5.000', $movements[0]->quantity_out);
        $this->assertSame($newer->id, $movements[1]->medicine_batch_id);
        $this->assertSame('2.000', $movements[1]->quantity_out);

        $this->assertSame('0.000', $older->refresh()->current_stock);
        $this->assertSame(MedicineBatchStatus::Depleted, $older->status);
        $this->assertSame('8.000', $newer->refresh()->current_stock);
    }

    public function test_saleable_stock_and_fefo_rules_exclude_invalid_batches(): void
    {
        $service = app(StockService::class);
        $medicine = Medicine::factory()->create([
            'classification' => 'obat_bebas',
            'is_active' => true,
        ]);
        $valid = MedicineBatch::factory()->for($medicine)->create([
            'expiry_date' => '2026-06-01',
            'current_stock' => '5.000',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        MedicineBatch::factory()->for($medicine)->create([
            'expiry_date' => null,
            'current_stock' => '8.000',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        MedicineBatch::factory()->for($medicine)->create([
            'expiry_date' => '2026-05-01',
            'current_stock' => '10.000',
            'status' => MedicineBatchStatus::Expired->value,
        ]);

        $alkes = Medicine::factory()->create([
            'classification' => 'alkes',
            'is_active' => true,
        ]);
        MedicineBatch::factory()->for($alkes)->create([
            'expiry_date' => null,
            'current_stock' => '8.000',
            'status' => MedicineBatchStatus::Available->value,
        ]);

        $this->assertSame('5.000', $service->saleableStock($medicine));
        $this->assertSame([$valid->id], $service->fefoBatches($medicine)->pluck('id')->all());
        $this->assertSame('8.000', $service->saleableStock($alkes));
    }

    public function test_manual_sale_batch_must_be_saleable_and_have_enough_stock(): void
    {
        $user = User::factory()->create();
        $medicine = Medicine::factory()->create([
            'is_active' => true,
        ]);
        $expired = MedicineBatch::factory()->for($medicine)->create([
            'expiry_date' => '2026-05-01',
            'current_stock' => '5.000',
            'status' => MedicineBatchStatus::Expired->value,
        ]);

        $this->expectException(ValidationException::class);

        app(StockService::class)->saleOut($medicine, '1.000', $user, [
            'batch' => $expired,
        ]);
    }

    public function test_batch_status_and_selling_price_helpers_follow_prd_priority(): void
    {
        $service = app(StockService::class);
        $medicine = Medicine::factory()->create([
            'selling_price' => '1200.00',
        ]);

        $fallbackBatch = MedicineBatch::factory()->for($medicine)->create([
            'selling_price' => null,
            'current_stock' => '1.000',
            'expiry_date' => '2026-06-01',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        $batchPrice = MedicineBatch::factory()->for($medicine)->create([
            'selling_price' => '1500.00',
            'current_stock' => '1.000',
            'expiry_date' => '2026-06-01',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        $quarantined = MedicineBatch::factory()->for($medicine)->make([
            'current_stock' => '0.000',
            'status' => MedicineBatchStatus::Quarantined->value,
        ]);
        $depleted = MedicineBatch::factory()->for($medicine)->make([
            'current_stock' => '0.000',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        $expired = MedicineBatch::factory()->for($medicine)->make([
            'current_stock' => '1.000',
            'expiry_date' => '2026-05-01',
            'status' => MedicineBatchStatus::Available->value,
        ]);

        $this->assertSame('1200.00', $service->sellingPriceFor($fallbackBatch));
        $this->assertSame('1500.00', $service->sellingPriceFor($batchPrice));
        $this->assertSame(MedicineBatchStatus::Quarantined, $service->resolveBatchStatus($quarantined));
        $this->assertSame(MedicineBatchStatus::Depleted, $service->resolveBatchStatus($depleted));
        $this->assertSame(MedicineBatchStatus::Expired, $service->resolveBatchStatus($expired));
    }

    public function test_usage_adjustment_and_cancel_methods_create_expected_movement_types(): void
    {
        $user = User::factory()->superAdmin()->create();
        $batch = MedicineBatch::factory()->create([
            'current_stock' => '10.000',
            'status' => MedicineBatchStatus::Available->value,
        ]);
        $service = app(StockService::class);

        $usage = $service->usageOut($batch, '2.000', $user, ['reference_id' => 1]);
        $adjustmentIn = $service->adjustmentIn($batch, '1.000', $user, ['reference_id' => 2]);
        $adjustmentOut = $service->adjustmentOut($batch, '3.000', $user, ['reference_id' => 3]);
        $cancelUsage = $service->cancelUsage($batch, '2.000', $user, ['reference_id' => 1]);
        $cancelAdjustmentIn = $service->cancelAdjustment($batch, '1.000', $user, [
            'reference_id' => 2,
            'reverse_of' => MovementType::AdjustmentIn,
        ]);
        $cancelAdjustmentOut = $service->cancelAdjustment($batch, '1.000', $user, [
            'reference_id' => 3,
            'reverse_of' => MovementType::AdjustmentOut,
        ]);

        $this->assertSame(MovementType::UsageOut, $usage->movement_type);
        $this->assertSame(MovementType::AdjustmentIn, $adjustmentIn->movement_type);
        $this->assertSame(MovementType::AdjustmentOut, $adjustmentOut->movement_type);
        $this->assertSame(MovementType::CancelUsage, $cancelUsage->movement_type);
        $this->assertSame(MovementType::CancelAdjustment, $cancelAdjustmentIn->movement_type);
        $this->assertSame('1.000', $cancelAdjustmentIn->quantity_out);
        $this->assertSame(MovementType::CancelAdjustment, $cancelAdjustmentOut->movement_type);
        $this->assertSame('1.000', $cancelAdjustmentOut->quantity_in);
        $this->assertSame('8.000', $batch->refresh()->current_stock);
    }

    public function test_activity_log_service_redacts_sensitive_data(): void
    {
        $user = User::factory()->create();

        $log = app(ActivityLogService::class)->record(
            'update',
            'settings',
            'password=secret token:abc Bearer topsecret',
            $user,
            [
                'safe' => 'visible',
                'password' => 'hidden',
                'nested' => [
                    'api_key' => 'very-hidden',
                ],
            ]
        );

        $this->assertSame($user->id, $log->user_id);
        $this->assertStringContainsString('visible', $log->description);
        $this->assertStringNotContainsString('secret', $log->description);
        $this->assertStringNotContainsString('hidden', $log->description);
        $this->assertStringContainsString('[redacted]', $log->description);
    }
}
