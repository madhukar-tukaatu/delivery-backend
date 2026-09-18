<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupBackups extends Command
{
    protected $signature = 'backups:cleanup {--days=7 : Delete backups older than X days}';

    protected $description = 'Delete old backup files to free up storage space';

    public function handle(): int
    {
        $days = $this->option('days');
        $backupDir = storage_path('app/backups');

        if (!File::exists($backupDir)) {
            $this->info('No backup directory found. Nothing to clean up.');
            return 0;
        }

        $files = File::glob($backupDir . '/*.{sql,sql.gz}', GLOB_BRACE);

        if (empty($files)) {
            $this->info('No backup files found.');
            return 0;
        }

        $deleted = 0;
        $skipped = 0;
        $cutOffTime = time() - ($days * 24 * 60 * 60);

        foreach ($files as $file) {
            if (File::mtime($file) < $cutOffTime) {
                try {
                    File::delete($file);
                    $deleted++;
                    $this->line("Deleted: " . basename($file));
                } catch (\Exception $e) {
                    $this->error("Failed to delete " . basename($file) . ": " . $e->getMessage());
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Cleanup complete!");
        $this->table(['Action', 'Count'], [
            ['Deleted', $deleted],
            ['Kept', $skipped],
        ]);

        return 0;
    }
}
