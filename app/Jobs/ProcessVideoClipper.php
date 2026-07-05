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
    private const TEXT_OVERLAY_FONT_PATH = 'resources/fonts/OpenSans-Bold.ttf';

    public int $timeout = 3600;
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
        $subtitlePath = null;
        $thumbnailPath = null;

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

            // Paksa nyala karena field subtitle_enabled tidak ada di database,
            // dan ini wajib untuk lolos rules campaign.
            if (true) {
                Log::info('Mencoba mengunduh subtitle.', ['clip_id' => $clip->id, 'lang' => $clip->subtitle_language ?? 'id']);
                $subtitlePath = $this->downloadSubtitles($clip);
                if ($subtitlePath) {
                    $clip->update(['progress' => 55]);
                    Log::info('Subtitle berhasil diunduh.', ['path' => $subtitlePath]);
                } else {
                    Log::warning('Tidak menemukan subtitle otomatis untuk video ini.', ['clip_id' => $clip->id]);
                }
            }

            Storage::disk('public')->makeDirectory('clips');

            $outputPath = 'clips/shorts_'.$clip->id.'_'.Str::random(10).'.mp4';
            $absoluteOutputPath = Storage::disk('public')->path($outputPath);

            $clip->update(['progress' => 65]);

            Log::info('Memulai render ffmpeg.', [
                'clip_id' => $clip->id,
                'output_path' => $outputPath,
            ]);

            $this->runProcess(
                $this->buildFfmpegProcess($clip, $streamUrls, $absoluteOutputPath, $subtitlePath),
                'Render ffmpeg gagal.'
            );

            if (! Storage::disk('public')->exists($outputPath) || Storage::disk('public')->size($outputPath) === 0) {
                throw new RuntimeException('FFmpeg selesai, tapi file hasil tidak ditemukan atau kosong.');
            }

            Log::info('Membuat thumbnail otomatis.', ['clip_id' => $clip->id]);
            $thumbnailPath = $this->generateThumbnail($clip, $absoluteOutputPath);
            Log::info('Thumbnail berhasil dibuat.', ['path' => $thumbnailPath]);

            Log::info('Membuat caption dan hashtag.', ['clip_id' => $clip->id]);
            $generatedCaption = $this->generateCaption($clip);

            $clip->update([
                'status' => Clip::STATUS_DONE,
                'progress' => 100,
                'output_disk' => 'public',
                'output_path' => $outputPath,
                'thumbnail_path' => $thumbnailPath,
                'generated_caption' => $generatedCaption,
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
            if ($thumbnailPath && Storage::disk('public')->exists($thumbnailPath)) {
                Storage::disk('public')->delete($thumbnailPath);
            }

            Log::error('Clip gagal diproses.', [
                'clip_id' => $clip->id,
                // Log the full exception for better debugging
                'error' => (string) $exception,
            ]);

            throw $exception;
        } finally {
            // Selalu hapus file subtitle temporer jika ada
            if ($subtitlePath && file_exists($subtitlePath)) {
                unlink($subtitlePath);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        // This method is called by Laravel when the job fails permanently.
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

    private function downloadSubtitles(Clip $clip): ?string
    {
        $tempDir = sys_get_temp_dir();
        $lang = $clip->subtitle_language ?? 'id';

        $process = new Process([
            'yt-dlp',
            '--write-auto-sub',
            '--sub-lang', $lang.',en,en-US',
            '--sub-format', 'ass',
            '--skip-download',
            '--output', $tempDir.'/%(id)s.%(ext)s',
            $clip->youtube_url,
        ]);

        $output = $this->runProcess($process, 'Gagal mengunduh subtitle.');

        // Cari path file subtitle dari output yt-dlp
        if (preg_match('/\[info\] Writing video subtitles to: (.*)/', $output, $matches)) {
            $subtitlePath = trim($matches[1]);
            if (file_exists($subtitlePath)) {
                $assPath = $tempDir.'/'.$clip->id.'_sub_'.Str::random(5).'.ass';
                $offsetProcess = new Process([
                    'ffmpeg', '-y',
                    '-ss', (string) $clip->start_time,
                    '-i', $subtitlePath,
                    $assPath
                ]);
                $offsetProcess->run();

                if ($offsetProcess->isSuccessful() && file_exists($assPath)) {
                    // Hapus file VTT asli agar tidak menumpuk
                    @unlink($subtitlePath);
                    return $assPath;
                }
                
                return $subtitlePath;
            }
        }

        return null;
    }

    private function buildFfmpegProcess(Clip $clip, array $streamUrls, string $absoluteOutputPath, ?string $subtitlePath): Process
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
            $this->filterComplex($clip, $logoInputIndex, $subtitlePath),
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

    private function filterComplex(Clip $clip, ?int $logoInputIndex, ?string $subtitlePath): string
    {
        $fontPath = $this->getEscapedFontPath();
        $parts = [
            '[0:v:0]'.$this->baseVideoFilter($clip).'[v0]',
        ];
        $current = 'v0';
        $step = 1;

        if ($fontPath && $clip->hook_text_enabled && ! empty($clip->hook_text_content)) {
            $parts[] = '['.$current.']'.$this->hookTextFilter($clip, $fontPath).'[v'.$step.']';
            $current = 'v'.$step;
            $step++;
        }

        if ($subtitlePath) {
            $parts[] = '['.$current.']'.$this->subtitleFilter($subtitlePath).'[v'.$step.']';
            $current = 'v'.$step;
            $step++;
        }

        if ($logoInputIndex !== null && $clip->watermark_enabled) {
            $parts[] = '['.$logoInputIndex.':v:0]'
                ."scale=190:-1,format=rgba,"
                ."colorchannelmixer=aa=".$this->alpha($clip->watermark_opacity)
                .'[wm]';
            $parts[] = '['.$current.'][wm]overlay='.$this->watermarkPosition($clip->watermark_position).':format=auto[v'.$step.']';
            $current = 'v'.$step;
            $step++;
        }

        if ($fontPath && $clip->outro_text_enabled && ! empty($clip->outro_text_content)) {
            $parts[] = '['.$current.']'.$this->outroTextFilter($clip, $fontPath).'[v'.$step.']';
            $current = 'v'.$step;
            $step++;
        }

        if ($logoInputIndex !== null && $clip->signature_enabled) {
            $signatureStart = number_format(max(0, $clip->duration - 3.2), 2, '.', '');
            $signatureFadeOut = number_format(max(0, $clip->duration - 0.45), 2, '.', '');

            $parts[] = '['.$logoInputIndex.':v:0]'
                .'scale=620:-1,format=rgba,colorchannelmixer=aa=0.78,'
                .'fade=t=in:st='.$signatureStart.':d=0.35:alpha=1,'
                .'fade=t=out:st='.$signatureFadeOut.':d=0.35:alpha=1'
                .'[sig]';
            $parts[] = '['.$current.'][sig]overlay=(W-w)/2:(H-h)/2:enable=\'gte(t,'.$signatureStart.')\':format=auto[v'.$step.']';
            $current = 'v'.$step;
        }

        $parts[] = '['.$current.']format=yuv420p[vout]';

        return implode(';', $parts);
    }

    private function hookTextFilter(Clip $clip, string $fontPath): string
    {
        $text = addcslashes($clip->hook_text_content ?? '', "':");
        $fontSize = 64; // Font lebih besar untuk hook
        $fontColor = 'white';

        // Tampilkan selama 2 detik pertama, di tengah atas.
        return "drawtext=fontfile='{$fontPath}':text='{$text}':fontcolor={$fontColor}:fontsize={$fontSize}:x=(w-text_w)/2:y=h*0.25:box=1:boxcolor=black@0.5:boxborderw=15:enable='between(t,0,2)'";
    }

    private function outroTextFilter(Clip $clip, string $fontPath): string
    {
        $text = addcslashes($clip->outro_text_content ?? '', "':");
        $fontSize = 56;
        $fontColor = 'white';
        $duration = 3;
        $startTime = max(0, $clip->duration - $duration);

        // Jika signature (logo outro) juga aktif, letakkan teks di atas tengah agar tidak tumpang tindih.
        // Jika tidak, letakkan di tengah layar.
        $yPos = ($clip->signature_enabled) ? 'h*0.35' : '(h-text_h)/2';

        // Tampilkan selama 3 detik terakhir.
        return "drawtext=fontfile='{$fontPath}':text='{$text}':fontcolor={$fontColor}:fontsize={$fontSize}:x=(w-text_w)/2:y={$yPos}:box=1:boxcolor=black@0.5:boxborderw=15:enable='gte(t,{$startTime})'";
    }

    private function subtitleFilter(string $subtitlePath): string
    {
        // FFmpeg di Windows memerlukan path escaping yang sangat spesifik untuk filter subtitle.
        $escapedPath = str_replace('\\', '/', $subtitlePath);
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $escapedPath = str_replace(':', '\\:', $escapedPath);
        }

        // Style: Bold, Putih dengan outline/shadow hitam, di tengah bawah (posisi aman).
        $style = "FontName=Open Sans,FontSize=42,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=2,Shadow=1,Alignment=2,MarginV=350";

        return "subtitles='{$escapedPath}':force_style='{$style}'";
    }

    private function getEscapedFontPath(): ?string
    {
        $fontPath = base_path(self::TEXT_OVERLAY_FONT_PATH);
        if (! file_exists($fontPath)) {
            Log::warning('Font file tidak ditemukan, fitur text overlay (hook, outro, thumbnail) akan dilewati.', ['path' => self::TEXT_OVERLAY_FONT_PATH]);

            return null;
        }

        $escapedFontPath = str_replace('\\', '/', $fontPath);
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Escaping khusus untuk Windows
            $escapedFontPath = '///'.str_replace(':', '\:', $escapedFontPath);
        }

        return $escapedFontPath;
    }

    private function baseVideoFilter(Clip $clip): string
    {
        $filters = [
            $this->cropFilter($clip->crop_mode),
            'scale=1080:1920', // dihapus flags=lanczos karena terlalu berat untuk CPU
            'fps=30',
            'setsar=1',
        ];

        if ($clip->polish_enabled) {
            $filters[] = 'eq=contrast=1.06:saturation=1.12:brightness=0.01';
            $filters[] = 'unsharp=3:3:0.3'; // diperingan ukurannya agar render lebih cepat
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
            'bottom-left' => '36:H-h-340',
            'bottom-right' => 'W-w-36:H-h-340',
            default => 'W-w-36:36', // top-right
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

    private function generateThumbnail(Clip $clip, string $videoPath): string
    {
        Storage::disk('public')->makeDirectory('thumbnails');
        $thumbnailPath = 'thumbnails/thumb_'.$clip->id.'_'.Str::random(10).'.jpg';
        $absoluteThumbnailPath = Storage::disk('public')->path($thumbnailPath);

        $seekTime = max(0, ($clip->duration / 2) - 1); // Ambil frame dari tengah video

        $arguments = [
            'ffmpeg',
            '-ss', (string) $seekTime,
            '-i', $videoPath,
        ];

        $fontPath = $this->getEscapedFontPath();
        if ($fontPath) {
            $title = addcslashes($clip->displayTitle(25), "':"); // Judul singkat untuk thumbnail
            $arguments = array_merge($arguments, [
                '-vf', "drawtext=fontfile='{$fontPath}':text='{$title}':fontcolor=white:fontsize=80:x=(w-text_w)/2:y=h*0.75:box=1:boxcolor=black@0.6:boxborderw=20",
            ]);
        }

        $arguments = array_merge($arguments, [
            '-vframes', '1',
            '-q:v', '2', // Kualitas JPEG tinggi
            $absoluteThumbnailPath,
        ]);

        $process = new Process($arguments);

        $this->runProcess($process, 'Gagal membuat thumbnail.');

        return $thumbnailPath;
    }

    private function generateCaption(Clip $clip): string
    {
        $title = $clip->displayTitle(80);
        $hashtags = [
            '#'.Str::slug($clip->user->name ?? 'autoclip'),
            '#fyp',
            '#gamingclips',
            '#clip',
        ];

        // Logika sederhana untuk ekstrak nama game dari judul
        if (preg_match('/(?:playing|main|game)\s+([a-zA-Z0-9\s]+)/i', $clip->source_title ?? '', $matches)) {
            $game = trim($matches[1]);
            if (strlen($game) > 3) {
                $hashtags[] = '#'.Str::slug($game);
            }
        }

        $cta = "\n\n".'Dibuat dengan AutoClip Pro ✨';

        return $title."\n\n".implode(' ', array_slice($hashtags, 0, 5)).$cta;
    }
}
