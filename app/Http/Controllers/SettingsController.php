<?php

namespace App\Http\Controllers;

use App\Enums\SettingType;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private const SETTING_TYPES = [
        'store_name' => SettingType::String,
        'store_address' => SettingType::String,
        'store_phone' => SettingType::String,
        'timezone' => SettingType::String,
        'default_minimum_stock' => SettingType::Number,
        'expiry_warning_days' => SettingType::Number,
        'pagination_per_page' => SettingType::Number,
        'report_export_formats' => SettingType::String,
        'upload_max_file_size_mb' => SettingType::Number,
        'theme_primary_color' => SettingType::String,
    ];

    private const DESCRIPTIONS = [
        'store_name' => 'Nama toko',
        'store_address' => 'Alamat toko',
        'store_phone' => 'Nomor kontak toko',
        'timezone' => 'Timezone aplikasi',
        'default_minimum_stock' => 'Default minimum stock obat baru',
        'expiry_warning_days' => 'Batas hari hampir kedaluwarsa',
        'pagination_per_page' => 'Jumlah data default per halaman',
        'report_export_formats' => 'Format export laporan yang didukung',
        'upload_max_file_size_mb' => 'Batas upload gambar obat',
        'theme_primary_color' => 'Warna utama tema hijau',
    ];

    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => $this->settingsPayload(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, ActivityLogService $activityLog): RedirectResponse
    {
        foreach ($request->allowedSettings() as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => (string) $value,
                    'type' => self::SETTING_TYPES[$key],
                    'description' => self::DESCRIPTIONS[$key],
                ]
            );
        }

        $activityLog->record('update', 'settings', 'Mengubah settings toko', $request->user(), [
            'keys' => array_keys($request->allowedSettings()),
        ], $request);

        return redirect()
            ->route('settings.index')
            ->with('success', 'Settings berhasil diperbarui.');
    }

    private function settingsPayload(): array
    {
        $settings = Setting::query()
            ->whereIn('key', UpdateSettingsRequest::ALLOWED_KEYS)
            ->get()
            ->keyBy('key');

        return collect(UpdateSettingsRequest::ALLOWED_KEYS)
            ->mapWithKeys(fn (string $key): array => [
                $key => [
                    'key' => $key,
                    'value' => $settings[$key]->value ?? $this->defaultValue($key),
                    'type' => (self::SETTING_TYPES[$key])->value,
                    'description' => self::DESCRIPTIONS[$key],
                ],
            ])
            ->all();
    }

    private function defaultValue(string $key): string
    {
        return match ($key) {
            'store_name' => 'Toko Obat',
            'store_address', 'store_phone' => '',
            'timezone' => 'Asia/Makassar',
            'default_minimum_stock' => '10',
            'expiry_warning_days' => '60',
            'pagination_per_page' => '20',
            'report_export_formats' => 'pdf,xlsx',
            'upload_max_file_size_mb' => '2',
            'theme_primary_color' => '#16a34a',
            default => '',
        };
    }
}
