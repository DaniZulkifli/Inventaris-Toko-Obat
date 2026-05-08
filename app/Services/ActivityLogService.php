<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    private const SENSITIVE_KEY_PARTS = [
        'password',
        'token',
        'secret',
        'credential',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'session',
    ];

    public function record(
        string $action,
        string $module,
        ?string $description = null,
        User|int|null $user = null,
        array $context = [],
        ?Request $request = null
    ): ActivityLog {
        $request ??= $this->currentRequest();
        $safeDescription = $this->sanitizeDescription($description ?? '');

        if ($context !== []) {
            $safeContext = json_encode($this->sanitizeContext($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $safeDescription = trim($safeDescription.' '.$safeContext);
        }

        return ActivityLog::query()->create([
            'user_id' => $this->userId($user),
            'action' => $action,
            'module' => $module,
            'description' => $safeDescription !== '' ? $safeDescription : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    public function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }

            $sanitized[$key] = is_array($value)
                ? $this->sanitizeContext($value)
                : $this->sanitizeDescription((string) $value);
        }

        return $sanitized;
    }

    public function sanitizeDescription(string $description): string
    {
        $patterns = [
            '/(password\s*[:=]\s*)[^\s,;]+/i',
            '/(token\s*[:=]\s*)[^\s,;]+/i',
            '/(secret\s*[:=]\s*)[^\s,;]+/i',
            '/(api[_-]?key\s*[:=]\s*)[^\s,;]+/i',
            '/(Bearer\s+)[A-Za-z0-9._\-]+/i',
        ];

        return preg_replace($patterns, '$1[redacted]', $description) ?? $description;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }

    private function userId(User|int|null $user): ?int
    {
        if ($user instanceof User) {
            return $user->id;
        }

        if ($user) {
            return (int) $user;
        }

        return Auth::id();
    }

    private function currentRequest(): ?Request
    {
        try {
            return request();
        } catch (\Throwable) {
            return null;
        }
    }
}
