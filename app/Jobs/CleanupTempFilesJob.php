<?php

namespace App\Jobs;

use App\Services\FileService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanupTempFilesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(FileService $fileService): void
    {
        // Clean up temporary files older than 24 hours
        $tempFilesBefore = Carbon::now()->subDay();
        $deletedTempCount = $fileService->cleanup('temp', $tempFilesBefore);

        Log::info('Cleaned up temporary files', [
            'deleted_count' => $deletedTempCount,
            'cutoff_time' => $tempFilesBefore->toDateTimeString(),
        ]);

        // Clean up old output files older than 30 days
        $outputFilesBefore = Carbon::now()->subDays(30);
        $deletedOutputCount = $fileService->cleanup('output', $outputFilesBefore);

        Log::info('Cleaned up old output files', [
            'deleted_count' => $deletedOutputCount,
            'cutoff_time' => $outputFilesBefore->toDateTimeString(),
        ]);
    }
}
