<?php

namespace Modules\Setting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class BackupController extends Controller
{
    protected $backupDir;
    
    public function __construct()
    {
        $this->backupDir = storage_path('app/backups');
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * List all backups
     */
    public function index()
    {
        $backups = [];
        
        if (file_exists($this->backupDir)) {
            $files = glob($this->backupDir . '/*.{sql,sql.gz}', GLOB_BRACE);
            
            foreach ($files as $file) {
                $backups[] = [
                    'filename' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'size_formatted' => $this->formatSize(filesize($file)),
                    'created_at' => filemtime($file),
                    'created_at_formatted' => date('Y-m-d H:i:s', filemtime($file)),
                    'type' => $this->getFileExtension($file),
                ];
            }
            
            usort($backups, function ($a, $b) {
                return $b['created_at'] <=> $a['created_at'];
            });
        }
        
        return ApiResponse::success([
            'total' => count($backups),
            'backups' => $backups,
        ]);
    }

    /**
     * Create a new backup
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['nullable', 'email'],
        ]);
        
        $email = $request->get('email');
        $backupPath = $this->createBackup();
        
        $response = [
            'success' => true,
            'message' => 'Backup created successfully',
            'backup' => [
                'filename' => basename($backupPath),
                'size' => filesize($backupPath),
                'size_formatted' => $this->formatSize(filesize($backupPath)),
                'created_at' => filemtime($backupPath),
            ],
        ];
        
        if ($email) {
            try {
                $this->emailBackup($backupPath, $email);
                $response['email_sent'] = true;
                $response['email'] = $email;
            } catch (\Exception $e) {
                $response['email_sent'] = false;
                $response['email_error'] = $e->getMessage();
            }
        }
        
        return ApiResponse::success($response);
    }

    /**
     * Download backup file
     */
    public function download($filename)
    {
        $filePath = $this->backupDir . '/' . $filename;
        
        if (!file_exists($filePath)) {
            return ApiResponse::error('Backup file not found', 404);
        }
        
        return response()->download($filePath);
    }

    /**
     * Delete backup file
     */
    public function destroy($filename)
    {
        $filePath = $this->backupDir . '/' . $filename;
        
        if (!file_exists($filePath)) {
            return ApiResponse::error('Backup file not found', 404);
        }
        
        if (unlink($filePath)) {
            return ApiResponse::success(null, 'Backup deleted successfully');
        }
        
        return ApiResponse::error('Failed to delete backup', 500);
    }

    /**
     * Create database backup
     */
    private function createBackup(): string
    {
        $date = date('Y-m-d_His');
        $backupFile = $this->backupDir . "/backup_{$date}.sql";
        
        $connection = config('database.connections.mysql');
        
        // Use docker exec to run mysqldump in the mysql container
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
            throw new \Exception('Database backup failed: ' . implode('\n', $output));
        }
        
        return $backupFile;
    }

    /**
     * Email backup to user
     */
    private function emailBackup(string $backupPath, string $email): void
    {
        Mail::send('setting::emails.backup-complete', [
            'backupFile' => basename($backupPath),
            'backupSize' => $this->formatSize(filesize($backupPath)),
        ], function ($message) use ($email, $backupPath) {
            $message->to($email)
                ->subject('Your Backup Request - Tukaatu Express')
                ->attach($backupPath);
        });
    }

    /**
     * Format file size
     */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get file extension
     */
    private function getFileExtension(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return $ext === 'gz' ? 'sql.gz' : $ext;
    }

    /**
     * Delete backups older than specified days
     */
    public function cleanup(Request $request)
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = $request->get('days', 7);
        $backupDir = storage_path('app/backups');

        if (!File::exists($backupDir)) {
            return ApiResponse::success([
                'message' => 'No backup directory found',
                'deleted' => 0,
            ]);
        }

        $files = glob($backupDir . '/*.{sql,sql.gz}', GLOB_BRACE);
        $cutOffTime = time() - ($days * 24 * 60 * 60);
        $deleted = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutOffTime) {
                try {
                    unlink($file);
                    $deleted++;
                } catch (\Exception $e) {
                    // Skip files that can't be deleted
                }
            }
        }

        return ApiResponse::success([
            'message' => 'Old backups cleaned up',
            'days_retained' => $days,
            'deleted' => $deleted,
        ]);
    }

    /**
     * Get backup schedules
     */
    public function schedules()
    {
        $schedules = DB::table('backup_schedules')->orderBy('type')->get();
        
        return ApiResponse::success([
            'schedules' => $schedules,
        ]);
    }

    /**
     * Update backup schedule
     */
    public function updateSchedule(Request $request, $id)
    {
        $request->validate([
            'enabled' => ['boolean'],
            'cron_time' => ['nullable', 'date_format:H:i'],
        ]);

        $schedule = DB::table('backup_schedules')->find($id);
        
        if (!$schedule) {
            return ApiResponse::error('Schedule not found', 404);
        }

        DB::table('backup_schedules')
            ->where('id', $id)
            ->update([
                'enabled' => $request->get('enabled', $schedule->enabled),
                'cron_time' => $request->get('cron_time', $schedule->cron_time),
                'updated_at' => now(),
            ]);

        return ApiResponse::success(null, 'Schedule updated');
    }

    /**
     * Trigger backup manually
     */
    public function triggerBackup(Request $request)
    {
        $request->validate([
            'type' => ['required', 'in:daily,weekly,monthly'],
        ]);

        $type = $request->get('type');
        
        // Get schedule
        $schedule = DB::table('backup_schedules')->where('type', $type)->first();
        
        if (!$schedule || !$schedule->enabled) {
            return ApiResponse::error('Backup schedule not enabled', 400);
        }

        $backupDir = storage_path('app/backups');
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $date = date('Y-m-d_His');
        $filename = "backup_{$type}_{$date}.sql";
        $backupFile = $backupDir . '/' . $filename;

        $connection = config('database.connections.mysql');

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
            return ApiResponse::error('Backup failed: ' . implode('\n', $output), 500);
        }

        // Update schedule
        DB::table('backup_schedules')
            ->where('id', $schedule->id)
            ->update([
                'last_run_at' => now(),
                'next_run_at' => $this->calculateNextRun($schedule->type, $schedule->cron_time, $schedule->cron_day, $schedule->cron_day_of_month),
                'updated_at' => now(),
            ]);

        return ApiResponse::success([
            'message' => 'Backup created successfully',
            'backup' => [
                'filename' => $filename,
                'size' => filesize($backupFile),
                'size_formatted' => $this->formatSize(filesize($backupFile)),
                'created_at' => now(),
                'type' => $type,
            ],
        ]);
    }

    /**
     * Calculate next run time
     */
    private function calculateNextRun(string $type, ?string $cronTime, ?string $cronDay, ?string $cronDayOfMonth): ?string
    {
        $now = now();
        
        switch ($type) {
            case 'daily':
                $next = $now->copy()->addDay()->setTimeFromTimeString($cronTime);
                break;
            case 'weekly':
                $dayMap = ['Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
                $targetDay = $dayMap[$cronDay] ?? 0;
                $currentDay = $now->dayOfWeek;
                $daysUntil = ($targetDay - $currentDay + 7) % 7;
                $next = $now->copy()->addDays($daysUntil)->setTimeFromTimeString($cronTime);
                break;
            case 'monthly':
                $targetDay = (int)($cronDayOfMonth ?? 1);
                $next = $now->copy()->addMonth()->day($targetDay)->setTimeFromTimeString($cronTime);
                break;
            default:
                $next = $now->copy()->addDay()->setTimeFromTimeString($cronTime);
        }
        
        return $next->toDateTimeString();
    }
}
