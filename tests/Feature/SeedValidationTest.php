<?php

namespace Tests\Feature;

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeedValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_demo_users_are_seeded_with_hashed_passwords_and_expected_roles(): void
    {
        $users = User::query()->whereIn('email', [
            'superadmin@tokoobat.test',
            'admin@tokoobat.test',
            'maya@tokoobat.test',
            'budi@tokoobat.test',
            'inactive@tokoobat.test',
        ])->get()->keyBy('email');

        $this->assertCount(5, $users);
        $this->assertSame('super_admin', $users['superadmin@tokoobat.test']->role->value);
        $this->assertSame('admin', $users['admin@tokoobat.test']->role->value);
        $this->assertSame('employee', $users['maya@tokoobat.test']->role->value);
        $this->assertFalse($users['inactive@tokoobat.test']->is_active);

        foreach ($users as $user) {
            $this->assertTrue(Hash::check('password', $user->password));
            $this->assertNotSame('password', $user->password);
        }
    }

    public function test_seed_counts_match_demo_baseline(): void
    {
        $this->assertSame(20, Medicine::query()->count());
        $this->assertSame(30, MedicineBatch::query()->count());
        $this->assertSame(5, PurchaseOrder::query()->count());
        $this->assertSame(10, Sale::query()->count());
        $this->assertSame(65, StockMovement::query()->count());
    }

    public function test_seed_transaction_status_rules_are_respected(): void
    {
        $this->assertSame(
            ['completed'],
            Sale::query()->distinct()->pluck('status')->map->value->all()
        );

        PurchaseOrder::query()
            ->where('status', 'draft')
            ->pluck('id')
            ->each(function (int $id): void {
                $this->assertFalse(
                    StockMovement::query()
                        ->where('reference_type', 'purchase_orders')
                        ->where('reference_id', $id)
                        ->exists(),
                    "Draft purchase order {$id} must not create stock movement."
                );
            });

        StockUsage::query()
            ->whereIn('status', ['draft', 'cancelled'])
            ->pluck('id')
            ->each(function (int $id): void {
                $this->assertFalse(
                    StockMovement::query()
                        ->where('reference_type', 'stock_usages')
                        ->where('reference_id', $id)
                        ->exists(),
                    "Draft/cancelled stock usage {$id} must not create stock movement."
                );
            });

        StockAdjustment::query()
            ->where('status', 'draft')
            ->pluck('id')
            ->each(function (int $id): void {
                $this->assertFalse(
                    StockMovement::query()
                        ->where('reference_type', 'stock_adjustments')
                        ->where('reference_id', $id)
                        ->exists(),
                    "Draft stock adjustment {$id} must not create stock movement."
                );
            });
    }

    public function test_each_batch_current_stock_matches_last_stock_movement(): void
    {
        MedicineBatch::query()->each(function (MedicineBatch $batch): void {
            $lastMovement = StockMovement::query()
                ->where('medicine_batch_id', $batch->id)
                ->orderByDesc('id')
                ->first();

            $this->assertNotNull($lastMovement, "Batch {$batch->id} has no movement.");
            $this->assertSame(
                $batch->current_stock,
                $lastMovement->stock_after,
                "Batch {$batch->batch_number} current stock must match last stock_after."
            );
        });
    }

    public function test_expired_and_near_expiry_batches_follow_seed_rules(): void
    {
        $currentDate = Carbon::parse('2026-05-08')->startOfDay();
        $warningDays = (int) Setting::query()->where('key', 'expiry_warning_days')->value('value');
        $warningEnd = $currentDate->copy()->addDays($warningDays);

        $expiredBatchNumbers = MedicineBatch::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $currentDate)
            ->pluck('batch_number')
            ->all();

        $nearExpiryBatchNumbers = MedicineBatch::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>', $currentDate)
            ->whereDate('expiry_date', '<=', $warningEnd)
            ->pluck('batch_number')
            ->all();

        $this->assertEqualsCanonicalizing(['BAT-VTC-2505', 'BAT-OMP-2504'], $expiredBatchNumbers);
        $this->assertContains('BAT-PCT-2501', $nearExpiryBatchNumbers);
        $this->assertContains('BAT-AMX-2506', $nearExpiryBatchNumbers);
        $this->assertContains('BAT-OBH-2505', $nearExpiryBatchNumbers);
        $this->assertContains('BAT-HDC-2506', $nearExpiryBatchNumbers);
    }

    public function test_unsaleable_batch_statuses_do_not_contribute_to_saleable_stock(): void
    {
        $saleableStock = MedicineBatch::query()
            ->where('status', 'available')
            ->where('current_stock', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>', '2026-05-08');
            })
            ->selectRaw('medicine_id, SUM(current_stock) as total_stock')
            ->groupBy('medicine_id')
            ->pluck('total_stock', 'medicine_id');

        $expected = [
            3 => '25.000',
            6 => '6.000',
            8 => '28.000',
            10 => '4.000',
            14 => null,
            16 => null,
        ];

        foreach ($expected as $medicineId => $stock) {
            $this->assertSame($stock, $saleableStock[$medicineId] ?? null);
        }
    }
}
