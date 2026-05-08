<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports): InertiaResponse
    {
        $role = $request->user()?->role?->value ?? $request->user()?->role;
        $filters = $reports->normalizeFilters($request->query(), $role);

        return Inertia::render('Reports/Index', [
            'report' => $reports->report($filters),
            'filters' => $filters,
            'options' => $reports->options($role),
        ]);
    }

    public function export(
        Request $request,
        ReportService $reports,
        ActivityLogService $activityLog
    ): SymfonyResponse {
        $request->validate([
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
        ]);

        $role = $request->user()?->role?->value ?? $request->user()?->role;
        $filters = $reports->normalizeFilters($request->query(), $role);
        $export = $reports->export((string) $request->query('format'), $filters);

        $activityLog->record('export_report', 'reports', "Export {$filters['jenis_laporan']} {$request->query('format')}", $request->user(), [
            'jenis_laporan' => $filters['jenis_laporan'],
            'format' => $request->query('format'),
            'filters' => $filters,
        ], $request);

        return response($export['content'], 200, [
            'Content-Type' => $export['mime'],
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
