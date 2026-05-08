<?php

namespace App\Http\Controllers;

use App\Models\DosageForm;
use App\Models\MedicineCategory;
use App\Models\Unit;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReferenceController extends Controller
{
    private const TYPES = [
        'categories' => MedicineCategory::class,
        'units' => Unit::class,
        'dosage_forms' => DosageForm::class,
    ];

    public function index(): Response
    {
        return Inertia::render('References/Index', [
            'references' => [
                'categories' => MedicineCategory::query()->withCount('medicines')->orderBy('name')->get(),
                'units' => Unit::query()->withCount('medicines')->orderBy('name')->get(),
                'dosage_forms' => DosageForm::query()->withCount('medicines')->orderBy('name')->get(),
            ],
        ]);
    }

    public function store(Request $request, string $type, ActivityLogService $activityLog): RedirectResponse
    {
        $modelClass = $this->modelClass($type);
        $data = $this->validatedData($request, $type);
        $record = $modelClass::query()->create($data);

        $activityLog->record('create', $this->moduleName($type), "Membuat {$record->name}", $request->user(), [
            'reference_id' => $record->id,
        ], $request);

        return redirect()->route('references.index')->with('success', 'Referensi berhasil dibuat.');
    }

    public function update(Request $request, string $type, int $id, ActivityLogService $activityLog): RedirectResponse
    {
        $record = $this->findRecord($type, $id);
        $record->update($this->validatedData($request, $type, $record));

        $activityLog->record('update', $this->moduleName($type), "Mengubah {$record->name}", $request->user(), [
            'reference_id' => $record->id,
        ], $request);

        return redirect()->route('references.index')->with('success', 'Referensi berhasil diperbarui.');
    }

    public function destroy(Request $request, string $type, int $id, ActivityLogService $activityLog): RedirectResponse
    {
        $record = $this->findRecord($type, $id);

        if ($record->medicines()->exists()) {
            throw ValidationException::withMessages([
                'reference' => 'Referensi yang sudah dipakai obat tidak dapat dihapus.',
            ]);
        }

        $name = $record->name;
        $record->delete();

        $activityLog->record('delete', $this->moduleName($type), "Menghapus {$name}", $request->user(), [
            'reference_id' => $id,
        ], $request);

        return redirect()->route('references.index')->with('success', 'Referensi berhasil dihapus.');
    }

    private function validatedData(Request $request, string $type, ?Model $record = null): array
    {
        $table = match ($type) {
            'categories' => 'medicine_categories',
            'units' => 'units',
            'dosage_forms' => 'dosage_forms',
            default => abort(404),
        };

        $rules = [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique($table, 'name')->ignore($record?->id),
            ],
        ];

        if ($type === 'units') {
            $rules['symbol'] = ['required', 'string', 'max:20'];
        } else {
            $rules['description'] = ['nullable', 'string', 'max:1000'];
        }

        return $request->validate($rules);
    }

    private function findRecord(string $type, int $id): Model
    {
        return $this->modelClass($type)::query()->findOrFail($id);
    }

    private function modelClass(string $type): string
    {
        abort_unless(array_key_exists($type, self::TYPES), 404);

        return self::TYPES[$type];
    }

    private function moduleName(string $type): string
    {
        return match ($type) {
            'categories' => 'medicine_categories',
            'units' => 'units',
            'dosage_forms' => 'dosage_forms',
            default => 'references',
        };
    }
}
