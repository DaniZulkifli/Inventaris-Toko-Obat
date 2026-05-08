<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $suppliers = Supplier::query()
            ->withCount(['batches', 'purchaseOrders'])
            ->when($filters['search'], function ($query, string $search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'contact_person' => $supplier->contact_person,
                'notes' => $supplier->notes,
                'is_active' => $supplier->is_active,
                'batches_count' => $supplier->batches_count,
                'purchase_orders_count' => $supplier->purchase_orders_count,
                'can_delete' => $supplier->batches_count === 0 && $supplier->purchase_orders_count === 0,
            ]);

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, ActivityLogService $activityLog): RedirectResponse
    {
        $supplier = Supplier::query()->create($this->validatedData($request));

        $activityLog->record('create', 'suppliers', "Membuat supplier {$supplier->name}", $request->user(), [
            'supplier_id' => $supplier->id,
        ], $request);

        return redirect()->route('suppliers.index')->with('success', 'Supplier berhasil dibuat.');
    }

    public function update(Request $request, Supplier $supplier, ActivityLogService $activityLog): RedirectResponse
    {
        $wasActive = $supplier->is_active;
        $supplier->update($this->validatedData($request));

        $activityLog->record('update', 'suppliers', "Mengubah supplier {$supplier->name}", $request->user(), [
            'supplier_id' => $supplier->id,
            'is_active' => $supplier->is_active,
        ], $request);

        if ((bool) $wasActive !== (bool) $supplier->is_active) {
            $activityLog->record($supplier->is_active ? 'activate' : 'deactivate', 'suppliers', ($supplier->is_active ? 'Mengaktifkan ' : 'Menonaktifkan ')."supplier {$supplier->name}", $request->user(), [
                'supplier_id' => $supplier->id,
                'is_active' => $supplier->is_active,
            ], $request);
        }

        return redirect()->route('suppliers.index', $request->only(['search', 'status', 'page']))
            ->with('success', 'Supplier berhasil diperbarui.');
    }

    public function destroy(Request $request, Supplier $supplier, ActivityLogService $activityLog): RedirectResponse
    {
        if ($supplier->batches()->exists() || $supplier->purchaseOrders()->exists()) {
            throw ValidationException::withMessages([
                'supplier' => 'Supplier yang sudah dipakai transaksi atau batch tidak dapat dihapus.',
            ]);
        }

        $name = $supplier->name;
        $supplier->delete();

        $activityLog->record('delete', 'suppliers', "Menghapus supplier {$name}", $request->user(), [
            'supplier_id' => $supplier->id,
        ], $request);

        return redirect()->route('suppliers.index', $request->only(['search', 'status', 'page']))
            ->with('success', 'Supplier berhasil dihapus.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
