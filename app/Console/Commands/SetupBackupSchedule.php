<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupBackupSchedule extends Command
{
    protected $signature = 'backups:schedule-setup';

    protected $description = 'Setup automated backup schedule in database';

    public function handle(): int
    {
        // Backup schedules table
        $table = 'backup_schedules';
        
        if (!DB::getSchemaBuilder()->hasTable($table)) {
            DB::statement("
                CREATE TABLE $table (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type ENUM('daily', 'weekly', 'monthly') NOT NULL,
                    enabled BOOLEAN DEFAULT true,
                    cron_time TIME NOT NULL,
                    cron_day VARCHAR(10) NULL,
                    cron_day_of_month VARCHAR(10) NULL,
                    last_run_at TIMESTAMP NULL,
                    next_run_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");
            
            $this->info('✅ Created backup_schedules table');
        }
        
        // Insert default schedules
        $schedules = [
            [
                'type' => 'daily',
                'cron_time' => '02:00:00',
                'cron_day' => null,
                'cron_day_of_month' => null,
            ],
            [
                'type' => 'weekly',
                'cron_time' => '03:00:00',
                'cron_day' => 'Sunday',
                'cron_day_of_month' => null,
            ],
            [
                'type' => 'monthly',
                'cron_time' => '04:00:00',
                'cron_day' => null,
                'cron_day_of_month' => '1',
            ],
        ];
        
        foreach ($schedules as $schedule) {
            $exists = DB::table($table)->where('type', $schedule['type'])->first();
            if (!$exists) {
                DB::table($table)->insert([
                    ...$schedule,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->info("✅ Added {$schedule['type']} backup schedule");
            } else {
                $this->info("✏️ Updated {$schedule['type']} backup schedule");
                DB::table($table)->where('type', $schedule['type'])->update([
                    'cron_time' => $schedule['cron_time'],
                    'cron_day' => $schedule['cron_day'],
                    'cron_day_of_month' => $schedule['cron_day_of_month'],
                    'updated_at' => now(),
                ]);
            }
        }
        
        $this->info("\n🎉 Backup schedule setup complete!");
        
        return self::SUCCESS;
    }
}
