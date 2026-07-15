<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Media Processing Tools Paths
    |--------------------------------------------------------------------------
    |
    | Di sini Anda dapat menentukan path absolut ke binary FFmpeg dan yt-dlp.
    | Ini membuat aplikasi lebih portabel dan tidak terlalu bergantung pada
    | environment variable PATH milik sistem.
    |
    | Contoh di Windows: 'C:/ffmpeg/bin/ffmpeg.exe'
    | Contoh di Linux: '/usr/bin/ffmpeg'
    |
    */

    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),

    'yt_dlp_path' => env('YTDLP_PATH', 'yt-dlp'),
];
