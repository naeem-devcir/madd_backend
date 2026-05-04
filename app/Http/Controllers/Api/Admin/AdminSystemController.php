<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PlaceholderApiController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Http\JsonResponse;

class AdminSystemController extends PlaceholderApiController
{
    /**
     * List all log files
     * Frontend expects: { date, size, modified }
     */
    public function logs(): JsonResponse
    {
        try {
            $logPath = storage_path('logs');
            if (!File::exists($logPath)) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $files = File::files($logPath);
            $logs = collect($files)
                ->filter(fn($f) => $f->getExtension() === 'log')
                ->map(function ($file) {
                    // Extract date portion from filename e.g. "laravel-2026-05-04.log" → "2026-05-04"
                    $filename = $file->getFilenameWithoutExtension(); // "laravel-2026-05-04"
                    $date = preg_replace('/^laravel-?/', '', $filename) ?: $filename;

                    return [
                        'date'     => $date ?: $file->getFilename(),
                        'size'     => round($file->getSize() / 1024, 2) . ' KB',
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    ];
                })
                ->sortByDesc('modified')
                ->values();

            return response()->json([
                'success' => true,
                'data'    => $logs,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to list logs',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show content of a specific log file
     * Frontend passes the `date` field from the log list
     */
    public function showLog(string $date): JsonResponse
    {
        try {
            // Try laravel-{date}.log first, then just {date}.log
            $candidates = [
                storage_path("logs/laravel-{$date}.log"),
                storage_path("logs/{$date}.log"),
                storage_path("logs/laravel.log"),
            ];

            $filePath = null;
            foreach ($candidates as $candidate) {
                if (File::exists($candidate)) {
                    $filePath = $candidate;
                    break;
                }
            }

            if (!$filePath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Log file not found',
                ], 404);
            }

            $content = File::get($filePath);

            // Truncate to last 2MB to prevent memory issues
            if (strlen($content) > 2 * 1024 * 1024) {
                $content = substr($content, -2 * 1024 * 1024);
                $content = "[Note: Content truncated to last 2MB]\n" . $content;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'date'    => $date,
                    'content' => $content,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to read log file',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear all log files
     */
    public function clearLogs(): JsonResponse
    {
        try {
            $logPath = storage_path('logs');
            $files   = File::files($logPath);

            foreach ($files as $file) {
                if ($file->getFilename() !== '.gitignore') {
                    File::delete($file->getPathname());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'All log files have been cleared',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear logs',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cache status
     * Frontend expects: { driver, ... }
     */
    public function cache(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'driver' => config('cache.default'),
                'prefix' => config('cache.prefix'),
                'stores' => array_keys(config('cache.stores')),
                'status' => 'operational',
            ],
        ]);
    }

    /**
     * Clear system cache
     */
    public function clearCache(): JsonResponse
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('view:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');

            return response()->json([
                'success' => true,
                'message' => 'System cache, views, config, and routes cleared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get queue status
     * Frontend expects: { failed_jobs: [...], stats: { pending, failed, processed } }
     */
    public function queues(): JsonResponse
    {
        try {
            // Pending jobs from jobs table
            $pendingCount = DB::table('jobs')->count();

            // Failed jobs
            $failedJobs = DB::table('failed_jobs')
                ->orderBy('failed_at', 'desc')
                ->get()
                ->map(function ($job) {
                    return [
                        'id'         => $job->id,
                        'connection' => $job->connection,
                        'queue'      => $job->queue,
                        'payload'    => json_decode($job->payload, true),
                        'exception'  => $job->exception,
                        'failed_at'  => $job->failed_at,
                    ];
                });

            $failedCount = $failedJobs->count();

            // Processed jobs: approximated from jobs_batches if available, else 0
            $processedCount = 0;
            try {
                $processedCount = DB::table('job_batches')->sum('total_jobs');
            } catch (\Exception) {
                $processedCount = 0;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'failed_jobs' => $failedJobs->values(),
                    'stats'       => [
                        'pending'   => $pendingCount,
                        'failed'    => $failedCount,
                        'processed' => $processedCount,
                    ],
                    'queue_connection' => config('queue.default'),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch queue status',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retry a failed job
     */
    public function retryJob(string $id): JsonResponse
    {
        try {
            $exitCode = Artisan::call('queue:retry', ['id' => [$id]]);

            if ($exitCode === 0) {
                return response()->json([
                    'success' => true,
                    'message' => "Job {$id} has been pushed back to the queue",
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => "Failed to retry job {$id}",
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrying job',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Flush all failed jobs
     */
    public function clearFailedJobs(): JsonResponse
    {
        try {
            Artisan::call('queue:flush');

            return response()->json([
                'success' => true,
                'message' => 'All failed jobs have been cleared',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear failed jobs',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get maintenance status
     * Frontend expects: { enabled: bool, message?, allowed_ips?, secret? }
     */
    public function maintenanceStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'enabled'     => app()->isDownForMaintenance(),
                'environment' => app()->environment(),
                'message'     => app()->isDownForMaintenance() ? 'System is under maintenance.' : null,
                'allowed_ips' => [],
            ],
        ]);
    }

    /**
     * Toggle maintenance mode
     */
    public function toggleMaintenance(): JsonResponse
    {
        try {
            if (app()->isDownForMaintenance()) {
                Artisan::call('up');
                $enabled = false;
                $message = 'Application is now LIVE';
            } else {
                Artisan::call('down');
                $enabled = true;
                $message = 'Application is now in MAINTENANCE mode';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data'    => [
                    'enabled' => $enabled,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle maintenance mode',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
