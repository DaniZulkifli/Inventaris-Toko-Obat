<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReportAccess
{
    private const ADMIN_REPORTS = [
        'stock',
        'low_stock',
        'out_of_stock',
        'expiry',
        'purchase',
        'sales',
        'stock_movement',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $role = $user?->role?->value ?? $user?->role;

        abort_if(! $user || ! in_array($role, ['super_admin', 'admin'], true), 403);

        $reportType = $request->query('jenis_laporan');

        if ($role === 'admin' && $reportType && ! in_array($reportType, self::ADMIN_REPORTS, true)) {
            abort(403);
        }

        return $next($request);
    }
}
