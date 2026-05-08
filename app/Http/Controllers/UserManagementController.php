<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'role' => $request->string('role')->toString(),
            'status' => $request->string('status')->toString(),
        ];

        $users = User::query()
            ->when($filters['search'], function ($query, string $search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($filters['role'], fn ($query, string $role) => $query->where('role', $role))
            ->when($filters['status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (User $user): array => $this->serializeUser($user));

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'stats' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
                'inactive' => User::query()->where('is_active', false)->count(),
                'super_admin' => User::query()->where('role', 'super_admin')->count(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request, ActivityLogService $activityLog): RedirectResponse
    {
        $data = $request->validated();

        $user = User::query()->create($data);
        $user->forceFill([
            'email_verified_at' => Carbon::now(config('app.timezone')),
        ])->save();

        $activityLog->record('create', 'users', "Membuat user {$user->email}", $request->user(), [
            'user_id' => $user->id,
            'role' => $user->role?->value ?? $user->role,
            'is_active' => $user->is_active,
        ], $request);

        return redirect()
            ->route('users.index')
            ->with('success', 'User berhasil dibuat.');
    }

    public function update(UpdateUserRequest $request, User $user, ActivityLogService $activityLog): RedirectResponse
    {
        $data = $request->validated();
        $this->ensureSuperAdminRemains($user, $data);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $wasActive = $user->is_active;
        $user->update($data);

        $activityLog->record('update', 'users', "Mengubah user {$user->email}", $request->user(), [
            'user_id' => $user->id,
            'role' => $user->role?->value ?? $user->role,
            'is_active' => $user->is_active,
        ], $request);

        if ((bool) $wasActive !== (bool) $user->is_active) {
            $activityLog->record($user->is_active ? 'activate' : 'deactivate', 'users', ($user->is_active ? 'Mengaktifkan ' : 'Menonaktifkan ')."user {$user->email}", $request->user(), [
                'user_id' => $user->id,
                'is_active' => $user->is_active,
            ], $request);
        }

        return redirect()
            ->route('users.index', $request->only(['search', 'role', 'status', 'page']))
            ->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user, ActivityLogService $activityLog): RedirectResponse
    {
        $this->ensureUserCanBeDeleted($user);
        $this->ensureSuperAdminRemains($user, [
            'role' => null,
            'is_active' => false,
        ]);

        $email = $user->email;
        $userId = $user->id;
        $user->delete();

        $activityLog->record('delete', 'users', "Menghapus user {$email}", $request->user(), [
            'deleted_user_id' => $userId,
        ], $request);

        return redirect()
            ->route('users.index', $request->only(['search', 'role', 'status', 'page']))
            ->with('success', 'User berhasil dihapus.');
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role?->value ?? $user->role,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at?->toDateTimeString(),
            'can_delete' => ! $this->hasOperationalData($user),
        ];
    }

    private function ensureSuperAdminRemains(User $user, array $newData): void
    {
        $isActiveSuperAdmin = ($user->role?->value ?? $user->role) === 'super_admin' && $user->is_active;
        $willStayActiveSuperAdmin = ($newData['role'] ?? $user->role?->value ?? $user->role) === 'super_admin'
            && (bool) ($newData['is_active'] ?? $user->is_active);

        if ($isActiveSuperAdmin && ! $willStayActiveSuperAdmin && $this->activeSuperAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'is_active' => 'Minimal harus ada satu Super Admin aktif.',
            ]);
        }
    }

    private function ensureUserCanBeDeleted(User $user): void
    {
        if ($this->hasOperationalData($user)) {
            throw ValidationException::withMessages([
                'user' => 'User yang sudah memiliki data operasional tidak dapat dihapus.',
            ]);
        }
    }

    private function hasOperationalData(User $user): bool
    {
        return $user->createdPurchaseOrders()->exists()
            || $user->receivedPurchaseOrders()->exists()
            || $user->sales()->exists()
            || $user->createdStockUsages()->exists()
            || $user->completedStockUsages()->exists()
            || $user->createdStockAdjustments()->exists()
            || $user->approvedStockAdjustments()->exists()
            || $user->stockMovements()->exists()
            || $user->activityLogs()->exists();
    }

    private function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('role', 'super_admin')
            ->where('is_active', true)
            ->count();
    }
}
