<?php

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Process\Process;
use Illuminate\Support\Str;

$tempDir = sys_get_temp_dir();
$youtube_url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
$start_time = 10;
$id = 999;

$process = new Process([
    'yt-dlp',
    '--write-auto-sub',
    '--sub-lang', 'id,en,en-US',
    '--sub-format', 'ass',
    '--skip-download',
    '--output', $tempDir.'/%(id)s.%(ext)s',
    $youtube_url,
]);

$process->run();
$output = $process->getOutput() . "\n" . $process->getErrorOutput();
echo "YT-DLP OUTPUT:\n" . $output . "\n\n";

if (preg_match('/\[info\] Writing video subtitles to: (.*)/', $output, $matches)) {
    $subtitlePath = trim($matches[1]);
    echo "Matched Subtitle Path: " . $subtitlePath . "\n";
    if (file_exists($subtitlePath)) {
        echo "File exists!\n";
        $assPath = $tempDir.'/'.$id.'_sub_'.Str::random(5).'.ass';
        $offsetProcess = new Process([
            'ffmpeg', '-y',
            '-ss', (string) $start_time,
            '-i', $subtitlePath,
            $assPath
        ]);
        $offsetProcess->run();

        if ($offsetProcess->isSuccessful() && file_exists($assPath)) {
            echo "FFMPEG Success! ASS Path: " . $assPath . "\n";
            echo "First 10 lines of ASS:\n";
            echo implode("\n", array_slice(file($assPath), 0, 10));
        } else {
            echo "FFMPEG Failed!\n";
            echo $offsetProcess->getErrorOutput();
        }
    } else {
        echo "File DOES NOT exist.\n";
    }
} else {
    echo "REGEX DID NOT MATCH.\n";
}
