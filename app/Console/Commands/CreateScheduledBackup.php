<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateScheduledBackup extends Command
{
    protected $signature = 'backups:scheduled {type=daily : Backup type (daily, weekly, monthly)}';

    protected $description = 'Create scheduled backup based on type (daily, weekly, monthly)';

    public function handle(): int
    {
        $backupType = $this->argument('type');
        $backupDir = storage_path('app/backups');
        
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $date = date('Y-m-d_His');
        $extension = '.sql';
        
        // Determine filename based on type
        switch ($backupType) {
            case 'daily':
                $filename = "backup_daily_{$date}{$extension}";
                break;
            case 'weekly':
                $filename = "backup_weekly_{$date}{$extension}";
                break;
            case 'monthly':
                $filename = "backup_monthly_{$date}{$extension}";
                break;
            default:
                $filename = "backup_{$date}{$extension}";
        }
        
        $backupFile = $backupDir . '/' . $filename;
        
        // Get DB credentials
        $connection = config('database.connections.mysql');
        
        // Run mysqldump
        $command = sprintf(
            'docker exec delivery-mysql mysqldump -u%s -p%s %s > %s 2>&1',
            escapeshellarg($connection['username']),
            escapeshellarg($connection['password']),
            escapeshellarg($connection['database']),
            escapeshellarg($backupFile)
        );
        
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar !== 0) {
            $this->error('Database backup failed: ' . implode('\n', $output));
            return self::FAILURE;
        }
        
        $this->info("✅ Backup created: $filename");
        $this->info("Type: $backupType");
        $this->info("Size: " . $this->formatSize(filesize($backupFile)));
        $this->info("Path: $backupFile");
        
        // Log to database
        DB::table('backups')->insert([
            'filename' => $filename,
            'path' => $backupFile,
            'type' => $backupType,
            'size' => filesize($backupFile),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return self::SUCCESS;
    }
    
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
