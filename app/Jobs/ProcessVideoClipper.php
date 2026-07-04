<?php

namespace App\Jobs;

use App\Models\Clip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessVideoClipper implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const BRAND_LOGO_PATH = 'resources/brand/framedrop_pp_v1.png';

    public int $timeout = 1200;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public int $clipId)
    {
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $clip = Clip::findOrFail($this->clipId);
        $outputPath = null;

        try {
            $clip->update([
                'status' => Clip::STATUS_PROCESSING,
                'progress' => 5,
                'error_message' => null,
                'started_at' => now(),
                'finished_at' => null,
            ]);

            Log::info('Membaca metadata YouTube.', ['clip_id' => $clip->id]);
            $metadata = $this->fetchMetadata($clip->youtube_url);

            $clip->update([
                'source_title' => $metadata['title'] ?? null,
                'source_duration' => isset($metadata['duration']) ? (int) $metadata['duration'] : null,
                'progress' => 20,
            ]);

            Log::info('Membaca direct stream URL YouTube.', ['clip_id' => $clip->id]);
            $streamUrls = $this->resolveStreamUrls($clip);

            $clip->update(['progress' => 45]);

            Storage::disk('public')->makeDirectory('clips');

            $outputPath = 'clips/shorts_'.$clip->id.'_'.Str::random(10).'.mp4';
            $absoluteOutputPath = Storage::disk('public')->path($outputPath);

            $clip->update(['progress' => 65]);

            Log::info('Memulai render ffmpeg tanpa menyimpan video mentah.', [
                'clip_id' => $clip->id,
                'output_path' => $outputPath,
            ]);

            $this->runProcess(
                $this->buildFfmpegProcess($clip, $streamUrls, $absoluteOutputPath),
                'Render ffmpeg gagal.'
            );

            if (! Storage::disk('public')->exists($outputPath) || Storage::disk('public')->size($outputPath) === 0) {
                throw new RuntimeException('FFmpeg selesai, tapi file hasil tidak ditemukan atau kosong.');
            }

            $clip->update([
                'status' => Clip::STATUS_DONE,
                'progress' => 100,
                'output_disk' => 'public',
                'output_path' => $outputPath,
                'finished_at' => now(),
            ]);

            Log::info('Clip selesai diproses.', [
                'clip_id' => $clip->id,
                'output_path' => $outputPath,
            ]);
        } catch (Throwable $exception) {
            if ($outputPath && Storage::disk('public')->exists($outputPath)) {
                Storage::disk('public')->delete($outputPath);
            }

            $clip->update([
                'status' => Clip::STATUS_FAILED,
                'progress' => min($clip->progress, 95),
                'error_message' => Str::limit($exception->getMessage(), 5000),
                'finished_at' => now(),
            ]);

            Log::error('Clip gagal diproses.', [
                'clip_id' => $clip->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Clip::whereKey($this->clipId)->update([
            'status' => Clip::STATUS_FAILED,
            'error_message' => Str::limit($exception->getMessage(), 5000),
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function fetchMetadata(string $youtubeUrl): array
    {
        $output = $this->runProcess(new Process([
            'yt-dlp',
            '--dump-json',
            '--no-playlist',
            '--no-warnings',
            $youtubeUrl,
        ]), 'Gagal membaca metadata YouTube.');

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function resolveStreamUrls(Clip $clip): array
    {
        $output = $this->runProcess(new Process([
            'yt-dlp',
            '--no-playlist',
            '--no-warnings',
            '-f',
            $this->formatSelector($clip->quality_height),
            '-g',
            $clip->youtube_url,
        ]), 'Gagal membaca direct stream URL YouTube.');

        $urls = array_values(array_filter(
            preg_split('/\R/', trim($output)) ?: [],
            fn (string $line): bool => str_starts_with(trim($line), 'http')
        ));

        if ($urls === []) {
            throw new RuntimeException('yt-dlp tidak mengembalikan stream URL yang bisa dipakai.');
        }

        return $urls;
    }

    private function buildFfmpegProcess(Clip $clip, array $streamUrls, string $absoluteOutputPath): Process
    {
        $arguments = ['ffmpeg', '-hide_banner', '-y'];
        $arguments = $this->appendInput($arguments, $streamUrls[0], $clip->start_time);

        if (isset($streamUrls[1])) {
            $arguments = $this->appendInput($arguments, $streamUrls[1], $clip->start_time);
            $audioMap = '1:a:0?';
            $streamInputCount = 2;
        } else {
            $audioMap = '0:a:0?';
            $streamInputCount = 1;
        }

        $logoInputIndex = null;
        $logoPath = base_path(self::BRAND_LOGO_PATH);

        if ($clip->watermark_enabled || $clip->signature_enabled) {
            if (! file_exists($logoPath)) {
                throw new RuntimeException('Logo brand belum tersedia di '.self::BRAND_LOGO_PATH.'.');
            }

            $logoInputIndex = $streamInputCount;
            $arguments = array_merge($arguments, [
                '-loop',
                '1',
                '-i',
                $logoPath,
            ]);
        }

        $arguments = array_merge($arguments, [
            '-t',
            (string) $clip->duration,
            '-filter_complex',
            $this->filterComplex($clip, $logoInputIndex),
            '-map',
            '[vout]',
            '-map',
            $audioMap,
            '-af',
            'loudnorm=I=-16:TP=-1.5:LRA=11',
            '-shortest',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '20',
            '-c:a',
            'aac',
            '-b:a',
            '160k',
            '-pix_fmt',
            'yuv420p',
            '-movflags',
            '+faststart',
            '-metadata',
            'title=AutoClip Pro - '.$clip->displayTitle(),
            $absoluteOutputPath,
        ]);

        $process = new Process($arguments);
        $process->setTimeout($this->timeout);

        return $process;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function appendInput(array $arguments, string $url, int $startTime): array
    {
        return array_merge($arguments, [
            '-ss',
            $this->secondsToTimestamp($startTime),
            '-reconnect',
            '1',
            '-reconnect_streamed',
            '1',
            '-reconnect_delay_max',
            '5',
            '-i',
            $url,
        ]);
    }

    private function filterComplex(Clip $clip, ?int $logoInputIndex): string
    {
        $parts = [
            '[0:v:0]'.$this->baseVideoFilter($clip).'[v0]',
        ];
        $current = 'v0';
        $step = 1;

        if ($logoInputIndex !== null && $clip->watermark_enabled) {
            $parts[] = '['.$logoInputIndex.':v:0]'
                ."crop=iw*0.76:ih*0.76:iw*0.12:ih*0.04,scale=190:-1,format=rgba,"
                ."colorkey=0x0b0b0b:0.18:0.08,colorchannelmixer=aa=".$this->alpha($clip->watermark_opacity)
                .'[wm]';
            $parts[] = '['.$current.'][wm]overlay='.$this->watermarkPosition($clip->watermark_position).':format=auto[v'.$step.']';
            $current = 'v'.$step;
            $step++;
        }

        if ($logoInputIndex !== null && $clip->signature_enabled) {
            $signatureStart = number_format(max(0, $clip->duration - 3.2), 2, '.', '');
            $signatureFadeOut = number_format(max(0, $clip->duration - 0.45), 2, '.', '');

            $parts[] = '['.$logoInputIndex.':v:0]'
                .'scale=620:-1,format=rgba,colorkey=0x0b0b0b:0.18:0.08,colorchannelmixer=aa=0.78,'
                .'fade=t=in:st='.$signatureStart.':d=0.35:alpha=1,'
                .'fade=t=out:st='.$signatureFadeOut.':d=0.35:alpha=1'
                .'[sig]';
            $parts[] = '['.$current.'][sig]overlay=(W-w)/2:(H-h)/2:enable=\'gte(t,'.$signatureStart.')\':format=auto[v'.$step.']';
            $current = 'v'.$step;
        }

        $parts[] = '['.$current.']format=yuv420p[vout]';

        return implode(';', $parts);
    }

    private function baseVideoFilter(Clip $clip): string
    {
        $filters = [
            $this->cropFilter($clip->crop_mode),
            'scale=1080:1920:flags=lanczos',
            'fps=30',
            'setsar=1',
        ];

        if ($clip->polish_enabled) {
            $filters[] = 'eq=contrast=1.06:saturation=1.12:brightness=0.01';
            $filters[] = 'unsharp=5:5:0.45:3:3:0.15';
        }

        return implode(',', $filters);
    }

    private function cropFilter(string $cropMode): string
    {
        $xFactor = match ($cropMode) {
            'left' => '0',
            'right' => '1',
            default => '0.5',
        };

        return "crop='if(gt(a,9/16),ih*9/16,iw)':'if(gt(a,9/16),ih,iw*16/9)':'if(gt(a,9/16),(iw-ih*9/16)*{$xFactor},0)':'if(gt(a,9/16),0,(ih-iw*16/9)*0.5)'";
    }

    private function watermarkPosition(string $position): string
    {
        return match ($position) {
            'top-left' => '36:36',
            'bottom-left' => '36:H-h-220',
            'bottom-right' => 'W-w-36:H-h-220',
            default => 'W-w-36:36',
        };
    }

    private function alpha(int $opacity): string
    {
        return number_format(max(20, min(90, $opacity)) / 100, 2, '.', '');
    }

    private function formatSelector(int $qualityHeight): string
    {
        return "bestvideo[height<={$qualityHeight}]+bestaudio/best[height<={$qualityHeight}]/best";
    }

    private function secondsToTimestamp(int $seconds): string
    {
        return gmdate('H:i:s', max(0, $seconds));
    }

    private function runProcess(Process $process, string $failureMessage): string
    {
        $process->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($failureMessage.' '.$output);
        }

        return $process->getOutput();
    }
}
