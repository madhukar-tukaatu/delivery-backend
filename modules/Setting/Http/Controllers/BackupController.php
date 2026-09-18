<?php

namespace Modules\Setting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

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
        
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s 2>&1',
            escapeshellarg($connection['host']),
            escapeshellarg($connection['port']),
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
}
