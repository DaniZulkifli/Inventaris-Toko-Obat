<?php

namespace App\Http\Controllers;

use App\Enums\MedicineBatchStatus;
use App\Enums\MedicineClassification;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Services\ActivityLogService;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MedicineBatchController extends Controller
{
    public function store(Request $request, StockService $stockService, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validatedData($request);
        $medicine = Medicine::query()->findOrFail($data['medicine_id']);
        $this->validateExpiryRule($medicine, $data['expiry_date'] ?? null);
        $data['batch_number'] = filled($data['batch_number'] ?? null) ? $data['batch_number'] : $this->generateBatchNumber();
        $this->ensureUniqueBatch($data);

        $openingStock = (float) ($data['initial_stock'] ?? 0);
        $requestedStatus = $data['status'] ?? MedicineBatchStatus::Available->value;
        $batch = DB::transaction(function () use ($data, $openingStock, $requestedStatus, $request, $stockService): MedicineBatch {
            $batch = MedicineBatch::query()->create([
                ...$data,
                'initial_stock' => '0.000',
                'current_stock' => '0.000',
                'status' => $requestedStatus === MedicineBatchStatus::Quarantined->value
                    ? MedicineBatchStatus::Quarantined->value
                    : MedicineBatchStatus::Depleted->value,
            ]);

            if ($openingStock > 0) {
                $stockService->openingStock($batch, $openingStock, $request->user(), [
                    'description' => "Saldo awal {$batch->batch_number}",
                ]);
                $batch->forceFill(['initial_stock' => number_format($openingStock, 3, '.', '')])->save();
            }

            return $batch->refresh();
        });

        $activityLog->record('create', 'medicine_batches', "Membuat batch {$batch->batch_number}", $request->user(), [
            'batch_id' => $batch->id,
            'medicine_id' => $batch->medicine_id,
        ], $request);

        return redirect()->route('medicines.index', ['tab' => 'batches'])->with('success', 'Batch berhasil dibuat.');
    }

    public function update(Request $request, MedicineBatch $medicineBatch, StockService $stockService, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validatedData($request, $medicineBatch);
        $medicine = Medicine::query()->findOrFail($data['medicine_id']);
        $this->validateExpiryRule($medicine, $data['expiry_date'] ?? null);
        $data['batch_number'] = filled($data['batch_number'] ?? null) ? $data['batch_number'] : $medicineBatch->batch_number;
        $this->ensureUniqueBatch($data, $medicineBatch);
        $oldStatus = $medicineBatch->status?->value ?? $medicineBatch->status;

        unset($data['initial_stock']);
        $medicineBatch->fill($data);
        $medicineBatch->status = ($data['status'] ?? null) === MedicineBatchStatus::Quarantined->value
            ? MedicineBatchStatus::Quarantined
            : $stockService->resolveBatchStatus($medicineBatch);
        $medicineBatch->save();

        $activityLog->record('update', 'medicine_batches', "Mengubah batch {$medicineBatch->batch_number}", $request->user(), [
            'batch_id' => $medicineBatch->id,
            'status' => $medicineBatch->status?->value ?? $medicineBatch->status,
        ], $request);

        $newStatus = $medicineBatch->status?->value ?? $medicineBatch->status;
        if ($oldStatus !== $newStatus) {
            $activityLog->record('change_status', 'medicine_batches', "Mengubah status batch {$medicineBatch->batch_number}", $request->user(), [
                'batch_id' => $medicineBatch->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ], $request);
        }

        return redirect()->route('medicines.index', ['tab' => 'batches'])->with('success', 'Batch berhasil diperbarui.');
    }

    public function destroy(Request $request, MedicineBatch $medicineBatch, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($this->hasOperationalData($medicineBatch)) {
            throw ValidationException::withMessages([
                'batch' => 'Batch yang sudah dipakai transaksi atau movement tidak dapat dihapus.',
            ]);
        }

        $batchNumber = $medicineBatch->batch_number;
        $medicineBatch->delete();

        $activityLog->record('delete', 'medicine_batches', "Menghapus batch {$batchNumber}", $request->user(), [
            'batch_id' => $medicineBatch->id,
        ], $request);

        return redirect()->route('medicines.index', ['tab' => 'batches'])->with('success', 'Batch berhasil dihapus.');
    }

    private function validatedData(Request $request, ?MedicineBatch $batch = null): array
    {
        return $request->validate([
            'medicine_id' => ['required', 'exists:medicines,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'batch_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'initial_stock' => [$batch ? 'nullable' : 'required', 'numeric', 'min:0'],
            'received_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(array_column(MedicineBatchStatus::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function validateExpiryRule(Medicine $medicine, ?string $expiryDate): void
    {
        $classification = $medicine->classification?->value ?? $medicine->classification;
        $allowsNoExpiry = in_array($classification, [
            MedicineClassification::Alkes->value,
            MedicineClassification::Other->value,
        ], true);

        if (! $allowsNoExpiry && blank($expiryDate)) {
            throw ValidationException::withMessages([
                'expiry_date' => 'Expiry date wajib diisi untuk obat selain alkes atau other.',
            ]);
        }
    }

    private function ensureUniqueBatch(array $data, ?MedicineBatch $ignore = null): void
    {
        $exists = MedicineBatch::query()
            ->where('medicine_id', $data['medicine_id'])
            ->where('batch_number', $data['batch_number'])
            ->where(function ($query) use ($data): void {
                filled($data['expiry_date'] ?? null)
                    ? $query->whereDate('expiry_date', $data['expiry_date'])
                    : $query->whereNull('expiry_date');
            })
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'batch_number' => 'Kombinasi obat, nomor batch, dan expiry date sudah ada.',
            ]);
        }
    }

    private function generateBatchNumber(): string
    {
        $prefix = 'AUTO-'.Carbon::today(config('app.timezone'))->format('Ymd').'-';
        $last = MedicineBatch::query()
            ->where('batch_number', 'like', "{$prefix}%")
            ->orderByDesc('batch_number')
            ->value('batch_number');
        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function hasOperationalData(MedicineBatch $batch): bool
    {
        return $batch->purchaseOrderItems()->exists()
            || $batch->saleItems()->exists()
            || $batch->stockUsageItems()->exists()
            || $batch->stockAdjustmentItems()->exists()
            || $batch->stockMovements()->exists();
    }

    private function authorizeManage(Request $request): void
    {
        $role = $request->user()?->role?->value ?? $request->user()?->role;

        abort_unless(in_array($role, ['super_admin', 'admin'], true), 403);
    }
}
