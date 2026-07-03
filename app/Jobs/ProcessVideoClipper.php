<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessVideoClipper implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $youtubeUrl;
    public $timeout = 900; // Kasih waktu 15 menit biar gak timeout saat render

    public function __construct($youtubeUrl)
    {
        $this->youtubeUrl = $youtubeUrl;
    }

    public function handle()
    {
        $idUnik = time() . '_' . uniqid();
        $pathMentah = storage_path("app/public/raw_{$idUnik}.mp4");
        $pathHasil = storage_path("app/public/shorts_{$idUnik}.mp4");

        Log::info("Memulai download video: {$this->youtubeUrl}");

        // 1. Ambil video MP4 kualitas terbaik maks 1080p pakai yt-dlp
        $cmdDownload = "yt-dlp -f \"bestvideo[ext=mp4][height<=1080]+bestaudio[ext=m4a]/best[ext=mp4]\" -o \"{$pathMentah}\" \"{$this->youtubeUrl}\"";
        shell_exec($cmdDownload);

        if (!file_exists($pathMentah)) {
            Log::error("Download gagal Bos!");
            return;
        }

        Log::info("Download sukses. Sekarang proses Auto-Crop 9:16 + Audio Booster...");

        // 2. Eksekusi FFmpeg: Potong 60 detik (dari detik ke-10), Crop Tengah (9:16), Boost Audio (loudnorm)
        $filterVideo = "crop=ih*9/16:ih";
        $filterAudio = "loudnorm";
        
        $cmdFFmpeg = "ffmpeg -i \"{$pathMentah}\" -ss 00:00:10 -t 60 -vf \"{$filterVideo}\" -af \"{$filterAudio}\" -c:v libx264 -crf 20 -c:a aac \"{$pathHasil}\"";
        shell_exec($cmdFFmpeg);

        // 3. Hapus sampah video mentah agar memori server hemat
        if (file_exists($pathMentah)) {
            unlink($pathMentah);
        }

        Log::info("Selesai! Hasil video tersimpan di: {$pathHasil}");
    }
}