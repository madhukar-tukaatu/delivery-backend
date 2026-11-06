<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSqlDumpCommand extends Command
{
    protected $signature = 'transfers:import-sql-dump
                            {--file=../delivery_dms_2026-11-04.sql : Path to SQL dump file}
                            {--only-master : Import only master tables (branches, merchants, routes, geography, roles, pricing)}';

    protected $description = 'Import master reference data (Branches, Coverage, Lanes, Routes, Rates, Merchants, Pricing, Users) from SQL dump';

    public function handle(): int
    {
        $file = $this->option('file');

        // Resolve path
        if (!file_exists($file)) {
            $fallback = base_path('../delivery_dms_2026-11-04.sql');
            if (file_exists($fallback)) {
                $file = $fallback;
            } elseif (file_exists(base_path('delivery_dms_2026-11-04.sql'))) {
                $file = base_path('delivery_dms_2026-11-04.sql');
            } else {
                $this->error("SQL dump file not found at: {$file}");
                return self::FAILURE;
            }
        }

        $this->info("==========================================================================");
        $this->info("   IMPORTING MASTER DATA FROM: " . realpath($file));
        $this->info("==========================================================================");

        $sql = file_get_contents($file);
        if (empty($sql)) {
            $this->error("Failed to read SQL dump file or file is empty.");
            return self::FAILURE;
        }

        $masterTables = [
            'nepal_provinces',
            'nepal_districts',
            'nepal_municipalities',
            'roles',
            'permissions',
            'model_has_roles',
            'role_has_permissions',
            'users',
            'branches',
            'coverage_locations',
            'branch_service_areas',
            'branch_transfer_lanes',
            'branch_transfer_routes',
            'branch_transfer_route_lanes',
            'branch_transfer_rate_tiers',
            'branch_route_rates',
            'pricing_settings',
            'service_types',
            'weight_rate_rules',
            'merchants',
            'merchant_pickup_locations',
            'merchant_documents',
            'merchant_api_keys',
        ];

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        $importedCount = 0;
        foreach ($masterTables as $table) {
            // Find INSERT INTO table ... ;
            $pattern = '/INSERT INTO [`"]?' . preg_quote($table, '/') . '[`"]?\s*.*?;/s';
            if (preg_match_all($pattern, $sql, $matches)) {
                $countStatements = count($matches[0]);
                $this->line("Importing {$table} ({$countStatements} statement blocks)...");

                // Truncate first to prevent duplicate primary keys
                try {
                    DB::table($table)->truncate();
                } catch (\Throwable) {
                    DB::statement("DELETE FROM {$table};");
                }

                foreach ($matches[0] as $stmt) {
                    try {
                        DB::unprepared($stmt);
                    } catch (\Throwable $e) {
                        $this->warn("  Notice on {$table}: " . substr($e->getMessage(), 0, 120));
                    }
                }

                $rowCount = DB::table($table)->count();
                $this->info("  ✓ {$table}: {$rowCount} rows loaded.");
                $importedCount++;
            } else {
                $this->line("  - {$table}: No insert statement found in dump.");
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->newLine();
        $this->info("==========================================================================");
        $this->info("✓ Successfully imported {$importedCount} master tables from SQL dump!");
        $this->info("==========================================================================");

        return self::SUCCESS;
    }
}

