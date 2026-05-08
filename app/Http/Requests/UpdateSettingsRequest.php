<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public const ALLOWED_KEYS = [
        'store_name',
        'store_address',
        'store_phone',
        'timezone',
        'default_minimum_stock',
        'expiry_warning_days',
        'pagination_per_page',
        'report_export_formats',
        'upload_max_file_size_mb',
        'theme_primary_color',
    ];

    public function authorize(): bool
    {
        $role = $this->user()?->role?->value ?? $this->user()?->role;

        return $role === 'super_admin';
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.store_name' => ['required', 'string', 'max:150'],
            'settings.store_address' => ['nullable', 'string', 'max:500'],
            'settings.store_phone' => ['nullable', 'string', 'max:30'],
            'settings.timezone' => ['required', 'string', 'max:64'],
            'settings.default_minimum_stock' => ['required', 'numeric', 'min:0'],
            'settings.expiry_warning_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'settings.pagination_per_page' => ['required', 'integer', 'min:5', 'max:100'],
            'settings.report_export_formats' => ['required', 'string', 'max:50', 'regex:/^(pdf|xlsx|csv)(,(pdf|xlsx|csv))*$/'],
            'settings.upload_max_file_size_mb' => ['required', 'integer', 'min:1', 'max:2'],
            'settings.theme_primary_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $unknownKeys = array_diff(array_keys($this->input('settings', [])), self::ALLOWED_KEYS);
            $sensitiveKeys = collect(array_keys($this->input('settings', [])))
                ->filter(fn (string $key): bool => str($key)->lower()->contains([
                    'password',
                    'token',
                    'api_key',
                    'apikey',
                    'secret',
                    'credential',
                ]))
                ->values()
                ->all();

            if ($unknownKeys !== []) {
                $validator->errors()->add('settings', 'Settings berisi key yang tidak diizinkan.');
            }

            if ($sensitiveKeys !== []) {
                $validator->errors()->add('settings', 'Settings tidak boleh menyimpan password, token, API key, secret, atau credential.');
            }
        });
    }

    public function allowedSettings(): array
    {
        return collect($this->validated('settings'))
            ->only(self::ALLOWED_KEYS)
            ->all();
    }
}
