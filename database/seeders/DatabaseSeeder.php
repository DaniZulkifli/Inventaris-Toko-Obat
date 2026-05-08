<?php

namespace Database\Seeders;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seededAt = Carbon::parse('2026-05-08 12:00:00', 'Asia/Makassar');
        $doc = base_path('project-docs/db-seed.md');

        if (! file_exists($doc)) {
            throw new \RuntimeException("Seed source not found: {$doc}");
        }

        $tables = [
            'Users' => 'users',
            'Medicine Categories' => 'medicine_categories',
            'Units' => 'units',
            'Dosage Forms' => 'dosage_forms',
            'Suppliers' => 'suppliers',
            'Medicines' => 'medicines',
            'Medicine Batches' => 'medicine_batches',
            'Purchase Orders' => 'purchase_orders',
            'Purchase Order Items' => 'purchase_order_items',
            'Sales' => 'sales',
            'Sale Items' => 'sale_items',
            'Stock Usages' => 'stock_usages',
            'Stock Usage Items' => 'stock_usage_items',
            'Stock Adjustments' => 'stock_adjustments',
            'Stock Adjustment Items' => 'stock_adjustment_items',
            'Stock Movements' => 'stock_movements',
            'Activity Logs' => 'activity_logs',
            'Settings' => 'settings',
        ];

        Schema::disableForeignKeyConstraints();

        foreach (array_reverse($tables) as $table) {
            app()->environment('testing')
                ? DB::table($table)->delete()
                : DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();

        $markdown = file_get_contents($doc);

        foreach ($tables as $section => $table) {
            $rows = $this->parseMarkdownTable($markdown, $section);

            $rows = array_map(function (array $row) use ($table, $seededAt): array {
                $row = $this->normalizeRow($row);

                if ($table === 'users') {
                    $row['password'] = Hash::make($row['password']);
                    $row['email_verified_at'] = $seededAt;
                    $row['remember_token'] = null;
                }

                $row['created_at'] ??= $seededAt;
                $row['updated_at'] ??= $row['created_at'];

                return $row;
            }, $rows);

            DB::table($table)->insert($rows);
        }
    }

    private function parseMarkdownTable(string $markdown, string $section): array
    {
        $pattern = '/^## '.preg_quote($section, '/').'\R(?P<body>.*?)(?=^## |\z)/ms';

        if (! preg_match($pattern, $markdown, $match)) {
            throw new \RuntimeException("Seed section not found: {$section}");
        }

        $lines = array_values(array_filter(
            preg_split('/\R/', $match['body']),
            fn (string $line): bool => str_starts_with(trim($line), '|')
        ));

        if (count($lines) < 2) {
            throw new \RuntimeException("Seed section has no table rows: {$section}");
        }

        $headers = $this->parseMarkdownRow($lines[0]);
        $rows = [];

        foreach (array_slice($lines, 2) as $line) {
            $values = $this->parseMarkdownRow($line);
            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    }

    private function parseMarkdownRow(string $line): array
    {
        $line = trim($line);
        $line = trim($line, '|');

        return array_map('trim', explode('|', $line));
    }

    private function normalizeRow(array $row): array
    {
        return array_map(function (string $value): mixed {
            return match ($value) {
                'NULL' => null,
                'true' => true,
                'false' => false,
                default => $value,
            };
        }, $row);
    }
}
