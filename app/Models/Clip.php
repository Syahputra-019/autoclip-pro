<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable([
    'youtube_url',
    'source_title',
    'source_duration',
    'status',
    'progress',
    'start_time',
    'duration',
    'quality_height',
    'crop_mode',
    'watermark_enabled',
    'watermark_position',
    'watermark_opacity',
    'signature_enabled',
    'polish_enabled',
    'output_disk',
    'output_path',
    'error_message',
    'started_at',
    'finished_at',
])]
class Clip extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_duration' => 'integer',
            'progress' => 'integer',
            'start_time' => 'integer',
            'duration' => 'integer',
            'quality_height' => 'integer',
            'watermark_enabled' => 'boolean',
            'watermark_opacity' => 'integer',
            'signature_enabled' => 'boolean',
            'polish_enabled' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE && filled($this->output_path);
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function displayTitle(): string
    {
        return $this->source_title ?: parse_url($this->youtube_url, PHP_URL_HOST) ?: 'YouTube Clip';
    }

    public function shortUrl(): string
    {
        return Str::limit($this->youtube_url, 52);
    }

    public function errorSummary(): ?string
    {
        return $this->error_message ? Str::limit($this->error_message, 180) : null;
    }

    public function downloadName(): string
    {
        return 'autoclip-'.$this->id.'.mp4';
    }
}
