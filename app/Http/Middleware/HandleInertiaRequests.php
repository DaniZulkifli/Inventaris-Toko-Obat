<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'navigation' => fn () => $this->navigation($request),
            'navigationGroups' => fn () => $this->navigationGroups($request),
            'breadcrumbs' => fn () => $this->breadcrumbs($request),
            'currentPage' => fn () => $this->currentPage($request),
            'store' => fn () => $this->storeSettings($request),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigation(Request $request): array
    {
        return collect($this->availableNavigationItems($request))
            ->map(fn (array $item): array => collect($item)->except(['roles', 'group', 'groupIcon', 'groupOrder'])->all())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigationGroups(Request $request): array
    {
        return collect($this->availableNavigationItems($request))
            ->groupBy('group')
            ->map(function ($items, string $group): array {
                $first = $items->first();

                return [
                    'key' => str($group)->slug()->toString(),
                    'label' => $group,
                    'icon' => $first['groupIcon'],
                    'order' => $first['groupOrder'],
                    'items' => $items
                        ->map(fn (array $item): array => collect($item)->except(['roles', 'group', 'groupIcon', 'groupOrder'])->all())
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('order')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function breadcrumbs(Request $request): array
    {
        $routeName = $request->route()?->getName();

        if (! $routeName || $routeName === 'dashboard' || ! $request->user()) {
            return [];
        }

        $item = collect($this->availableNavigationItems($request))
            ->firstWhere('route', $routeName);

        $label = $item['label'] ?? $this->pageLabels()[$routeName] ?? str($routeName)->replace('.', ' ')->title()->toString();
        $group = $item['group'] ?? null;

        return array_values(array_filter([
            ['label' => 'Dashboard', 'href' => route('dashboard')],
            $group ? ['label' => $group, 'href' => null] : null,
            ['label' => $label, 'href' => null],
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function currentPage(Request $request): array
    {
        $routeName = $request->route()?->getName();
        $item = collect($this->availableNavigationItems($request))
            ->firstWhere('route', $routeName);

        $label = $item['label'] ?? $this->pageLabels()[$routeName] ?? 'Dashboard';

        return [
            'title' => $label,
            'route' => $routeName,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableNavigationItems(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        $role = $user->role?->value ?? $user->role;

        return collect($this->navigationItems())
            ->filter(fn (array $item): bool => in_array($role, $item['roles'], true))
            ->map(fn (array $item): array => [
                ...$item,
                'href' => route($item['route']),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function navigationItems(): array
    {
        return [
            $this->navItem('Dashboard', 'dashboard', 'LayoutDashboard', 'Utama', 'LayoutDashboard', 10, ['super_admin', 'admin', 'employee']),

            $this->navItem('Referensi Obat', 'references.index', 'LibraryBig', 'Master Data', 'Database', 20, ['super_admin', 'admin']),
            $this->navItem('Supplier', 'suppliers.index', 'Truck', 'Master Data', 'Database', 20, ['super_admin', 'admin']),
            $this->navItem('Obat dan Batch', 'medicines.index', 'Pill', 'Master Data', 'Database', 20, ['super_admin', 'admin']),

            $this->navItem('Obat Aktif', 'medicines.index', 'Pill', 'Monitoring', 'Activity', 40, ['employee']),
            $this->navItem('Purchase Order', 'purchase-orders.index', 'ClipboardList', 'Transaksi', 'ReceiptText', 30, ['super_admin', 'admin']),
            $this->navItem('Penjualan', 'sales.index', 'ShoppingCart', 'Transaksi', 'ReceiptText', 30, ['super_admin', 'admin', 'employee']),
            $this->navItem('Riwayat Penjualan Saya', 'sales.my-history', 'History', 'Transaksi', 'ReceiptText', 30, ['employee']),
            $this->navItem('Stock Usage', 'stock-usages.index', 'ArchiveX', 'Transaksi', 'ReceiptText', 30, ['super_admin', 'admin']),
            $this->navItem('Stock Adjustment', 'stock-adjustments.index', 'SlidersHorizontal', 'Transaksi', 'ReceiptText', 30, ['super_admin', 'admin']),

            $this->navItem('Monitoring Stok dan Batch', 'stock.summary', 'Activity', 'Monitoring', 'Activity', 40, ['super_admin', 'admin', 'employee']),
            $this->navItem('Stock Movement', 'stock-movements.index', 'ArrowLeftRight', 'Monitoring', 'Activity', 40, ['super_admin', 'admin']),

            $this->navItem('Laporan', 'reports.index', 'FileBarChart', 'Laporan', 'FileBarChart', 50, ['super_admin', 'admin']),

            $this->navItem('Users', 'users.index', 'Users', 'Administrasi', 'Shield', 60, ['super_admin']),
            $this->navItem('Settings', 'settings.index', 'Settings', 'Administrasi', 'Shield', 60, ['super_admin']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function navItem(
        string $label,
        string $route,
        string $icon,
        string $group,
        string $groupIcon,
        int $groupOrder,
        array $roles
    ): array {
        return compact('label', 'route', 'icon', 'group', 'groupIcon', 'groupOrder', 'roles');
    }

    /**
     * @return array<string, string>
     */
    private function pageLabels(): array
    {
        return [
            'profile.edit' => 'Profil',
        ];
    }

    /**
     * @return array<string, string>|array{}
     */
    private function storeSettings(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        $defaults = [
            'store_name' => 'Toko Obat',
            'store_address' => '',
            'store_phone' => '',
            'timezone' => config('app.timezone'),
            'pagination_per_page' => '20',
        ];

        return [
            ...$defaults,
            ...Setting::query()
                ->whereIn('key', array_keys($defaults))
                ->pluck('value', 'key')
                ->all(),
        ];
    }
}
