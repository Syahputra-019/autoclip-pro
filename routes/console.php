<?php

use App\Models\Clip;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('clips:cleanup {--days=7}', function (): int {
    $days = max(1, (int) $this->option('days'));
    $cutoff = now()->subDays($days);
    $deletedFiles = 0;
    $deletedRows = 0;

    Clip::query()
        ->where('created_at', '<', $cutoff)
        ->chunkById(100, function ($clips) use (&$deletedFiles, &$deletedRows): void {
            foreach ($clips as $clip) {
                if ($clip->output_path && Storage::disk($clip->output_disk)->exists($clip->output_path)) {
                    Storage::disk($clip->output_disk)->delete($clip->output_path);
                    $deletedFiles++;
                }

                $clip->delete();
                $deletedRows++;
            }
        });

    $this->info("Cleanup selesai: {$deletedRows} clip dihapus, {$deletedFiles} file dibersihkan.");

    return self::SUCCESS;
})->purpose('Delete old clip records and generated video files');

Schedule::command('clips:cleanup --days=7')->daily();
