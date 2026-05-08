<?php

namespace App\Http\Controllers;

use App\Enums\MedicineBatchStatus;
use App\Enums\MedicineClassification;
use App\Models\DosageForm;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MedicineController extends Controller
{
    public function index(Request $request): Response
    {
        $role = $request->user()?->role?->value ?? $request->user()?->role;
        $canManage = in_array($role, ['super_admin', 'admin'], true);
        $medicineFilters = [
            'search' => $request->string('search')->toString(),
            'classification' => $request->string('classification')->toString(),
            'status' => $request->string('status')->toString(),
        ];
        $batchFilters = [
            'batch_search' => $request->string('batch_search')->toString(),
            'batch_status' => $request->string('batch_status')->toString(),
            'supplier_id' => $request->string('supplier_id')->toString(),
            'medicine_id' => $request->string('medicine_id')->toString(),
        ];

        $medicines = Medicine::query()
            ->with(['category:id,name', 'unit:id,name,symbol', 'dosageForm:id,name'])
            ->withCount('batches')
            ->when(! $canManage, fn ($query) => $query->where('is_active', true))
            ->when($medicineFilters['search'], function ($query, string $search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('generic_name', 'like', "%{$search}%");
                });
            })
            ->when($medicineFilters['classification'], fn ($query, string $classification) => $query->where('classification', $classification))
            ->when($medicineFilters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($medicineFilters['status'] === 'inactive' && $canManage, fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(10, ['*'], 'medicines_page')
            ->withQueryString()
            ->through(fn (Medicine $medicine): array => $this->serializeMedicine($medicine));

        $batches = $canManage
            ? $this->batchQuery($batchFilters)->paginate(10, ['*'], 'batches_page')->withQueryString()->through(fn ($batch) => $this->serializeBatch($batch))
            : null;

        return Inertia::render('Medicines/Index', [
            'medicines' => $medicines,
            'batches' => $batches,
            'filters' => [
                ...$medicineFilters,
                ...$batchFilters,
            ],
            'options' => $this->options(),
            'canManage' => $canManage,
        ]);
    }

    public function store(Request $request, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validatedData($request);
        $data['code'] = filled($data['code'] ?? null) ? $data['code'] : $this->generateMedicineCode();
        $image = $data['image_path'] ?? null;
        unset($data['image_path']);

        if ($image instanceof UploadedFile) {
            $data['image_path'] = $this->storeImage($image);
        }

        $medicine = Medicine::query()->create($data);

        $activityLog->record('create', 'medicines', "Membuat obat {$medicine->name}", $request->user(), [
            'medicine_id' => $medicine->id,
            'code' => $medicine->code,
        ], $request);

        return redirect()->route('medicines.index')->with('success', 'Obat berhasil dibuat.');
    }

    public function update(Request $request, Medicine $medicine, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validatedData($request, $medicine);
        $data['code'] = filled($data['code'] ?? null) ? $data['code'] : $medicine->code;
        $original = $medicine->getOriginal();
        $image = $data['image_path'] ?? null;
        unset($data['image_path']);

        if ($image instanceof UploadedFile) {
            $data['image_path'] = $this->storeImage($image, $medicine->image_path);
        }

        $medicine->update($data);

        $activityLog->record('update', 'medicines', "Mengubah obat {$medicine->name}", $request->user(), [
            'medicine_id' => $medicine->id,
            'code' => $medicine->code,
        ], $request);

        $this->recordMedicineSecurityAudit($activityLog, $request, $medicine, $original);

        return redirect()->route('medicines.index', $request->only(['search', 'classification', 'status', 'medicines_page']))
            ->with('success', 'Obat berhasil diperbarui.');
    }

    public function destroy(Request $request, Medicine $medicine, ActivityLogService $activityLog): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($this->medicineHasOperationalData($medicine)) {
            throw ValidationException::withMessages([
                'medicine' => 'Obat yang sudah memiliki batch atau transaksi tidak dapat dihapus.',
            ]);
        }

        $name = $medicine->name;
        $medicine->delete();

        $activityLog->record('delete', 'medicines', "Menghapus obat {$name}", $request->user(), [
            'medicine_id' => $medicine->id,
        ], $request);

        return redirect()->route('medicines.index', $request->only(['search', 'classification', 'status', 'medicines_page']))
            ->with('success', 'Obat berhasil dihapus.');
    }

    private function batchQuery(array $filters)
    {
        return \App\Models\MedicineBatch::query()
            ->with(['medicine:id,name,code,classification', 'supplier:id,name'])
            ->when($filters['batch_search'], function ($query, string $search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('batch_number', 'like', "%{$search}%")
                        ->orWhereHas('medicine', fn ($medicineQuery) => $medicineQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%"));
                });
            })
            ->when($filters['batch_status'], fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['supplier_id'], fn ($query, string $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['medicine_id'], fn ($query, string $medicineId) => $query->where('medicine_id', $medicineId))
            ->latest('id');
    }

    private function validatedData(Request $request, ?Medicine $medicine = null): array
    {
        return $request->validate([
            'medicine_category_id' => ['required', 'exists:medicine_categories,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'dosage_form_id' => ['nullable', 'exists:dosage_forms,id'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('medicines', 'code')->ignore($medicine?->id)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('medicines', 'barcode')->ignore($medicine?->id)],
            'name' => ['required', 'string', 'max:150'],
            'generic_name' => ['nullable', 'string', 'max:150'],
            'manufacturer' => ['nullable', 'string', 'max:150'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'active_ingredient' => ['nullable', 'string', 'max:1000'],
            'strength' => ['nullable', 'string', 'max:100'],
            'classification' => ['required', Rule::in(array_column(MedicineClassification::cases(), 'value'))],
            'requires_prescription' => ['required', 'boolean'],
            'default_purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'minimum_stock' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'numeric', 'min:0'],
            'storage_instruction' => ['nullable', 'string', 'max:1000'],
            'image_path' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$this->medicineImageMaxKilobytes()],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function recordMedicineSecurityAudit(
        ActivityLogService $activityLog,
        Request $request,
        Medicine $medicine,
        array $original
    ): void {
        $priceChanges = [];

        foreach (['default_purchase_price', 'selling_price'] as $field) {
            if (round((float) ($original[$field] ?? 0), 2) !== round((float) $medicine->{$field}, 2)) {
                $priceChanges[$field] = [
                    'old' => $original[$field] ?? null,
                    'new' => $medicine->{$field},
                ];
            }
        }

        if ($priceChanges !== []) {
            $activityLog->record('change_price', 'medicines', "Mengubah harga obat {$medicine->name}", $request->user(), [
                'medicine_id' => $medicine->id,
                'changes' => $priceChanges,
            ], $request);
        }

        if ((bool) ($original['is_active'] ?? false) !== (bool) $medicine->is_active) {
            $activityLog->record($medicine->is_active ? 'activate' : 'deactivate', 'medicines', ($medicine->is_active ? 'Mengaktifkan ' : 'Menonaktifkan ')."obat {$medicine->name}", $request->user(), [
                'medicine_id' => $medicine->id,
                'is_active' => $medicine->is_active,
            ], $request);
        }
    }

    private function storeImage(UploadedFile $image, ?string $oldPath = null): string
    {
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        return $image->store('medicine-images', 'public');
    }

    private function medicineImageMaxKilobytes(): int
    {
        $configuredMb = (int) (Setting::query()->where('key', 'upload_max_file_size_mb')->value('value') ?: 2);

        return min(max($configuredMb, 1), 2) * 1024;
    }

    private function serializeMedicine(Medicine $medicine): array
    {
        return [
            'id' => $medicine->id,
            'medicine_category_id' => $medicine->medicine_category_id,
            'unit_id' => $medicine->unit_id,
            'dosage_form_id' => $medicine->dosage_form_id,
            'code' => $medicine->code,
            'barcode' => $medicine->barcode,
            'name' => $medicine->name,
            'generic_name' => $medicine->generic_name,
            'manufacturer' => $medicine->manufacturer,
            'registration_number' => $medicine->registration_number,
            'active_ingredient' => $medicine->active_ingredient,
            'strength' => $medicine->strength,
            'classification' => $medicine->classification?->value ?? $medicine->classification,
            'requires_prescription' => $medicine->requires_prescription,
            'default_purchase_price' => $medicine->default_purchase_price,
            'selling_price' => $medicine->selling_price,
            'minimum_stock' => $medicine->minimum_stock,
            'reorder_level' => $medicine->reorder_level,
            'storage_instruction' => $medicine->storage_instruction,
            'image_path' => $medicine->image_path,
            'is_active' => $medicine->is_active,
            'category' => $medicine->category?->name,
            'unit' => $medicine->unit?->symbol,
            'dosage_form' => $medicine->dosageForm?->name,
            'batches_count' => $medicine->batches_count,
            'can_delete' => ! $this->medicineHasOperationalData($medicine),
        ];
    }

    private function serializeBatch($batch): array
    {
        return [
            'id' => $batch->id,
            'medicine_id' => $batch->medicine_id,
            'supplier_id' => $batch->supplier_id,
            'medicine' => $batch->medicine?->name,
            'medicine_code' => $batch->medicine?->code,
            'classification' => $batch->medicine?->classification?->value ?? $batch->medicine?->classification,
            'supplier' => $batch->supplier?->name,
            'batch_number' => $batch->batch_number,
            'expiry_date' => $batch->expiry_date?->toDateString(),
            'purchase_price' => $batch->purchase_price,
            'selling_price' => $batch->selling_price,
            'initial_stock' => $batch->initial_stock,
            'current_stock' => $batch->current_stock,
            'received_date' => $batch->received_date?->toDateString(),
            'status' => $batch->status?->value ?? $batch->status,
            'notes' => $batch->notes,
        ];
    }

    private function options(): array
    {
        return [
            'categories' => MedicineCategory::query()->orderBy('name')->get(['id', 'name']),
            'units' => Unit::query()->orderBy('name')->get(['id', 'name', 'symbol']),
            'dosage_forms' => DosageForm::query()->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'active_suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'medicines' => Medicine::query()->orderBy('name')->get(['id', 'code', 'name']),
            'classifications' => collect(MedicineClassification::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => str($case->value)->replace('_', ' ')->title()->toString(),
            ])->values(),
            'batch_statuses' => collect(MedicineBatchStatus::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => str($case->value)->replace('_', ' ')->title()->toString(),
            ])->values(),
        ];
    }

    private function generateMedicineCode(): string
    {
        $prefix = 'MED-'.Carbon::today(config('app.timezone'))->format('Ymd').'-';
        $lastCode = Medicine::query()
            ->where('code', 'like', "{$prefix}%")
            ->orderByDesc('code')
            ->value('code');
        $sequence = $lastCode ? ((int) substr($lastCode, -4)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function medicineHasOperationalData(Medicine $medicine): bool
    {
        return $medicine->batches()->exists()
            || $medicine->purchaseOrderItems()->exists()
            || $medicine->saleItems()->exists()
            || $medicine->stockUsageItems()->exists()
            || $medicine->stockAdjustmentItems()->exists()
            || $medicine->stockMovements()->exists();
    }

    private function authorizeManage(Request $request): void
    {
        $role = $request->user()?->role?->value ?? $request->user()?->role;

        abort_unless(in_array($role, ['super_admin', 'admin'], true), 403);
    }
}
