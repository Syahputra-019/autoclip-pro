<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoClipper;
use App\Models\Clip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClipperFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_successful_response(): void
    {
        $response = $this->get(route('clipper.index'));

        $response->assertOk();
        $response->assertSee('AutoClip Pro');
        $response->assertSee('Brand Kit');
    }

    public function test_only_youtube_urls_are_accepted(): void
    {
        $response = $this->from(route('clipper.index'))->post(route('clip.store'), [
            'youtube_url' => 'https://example.com/watch?v=abc',
        ]);

        $response->assertRedirect(route('clipper.index'));
        $response->assertSessionHasErrors('youtube_url');
        $this->assertDatabaseCount('clips', 0);
    }

    public function test_youtube_url_creates_clip_and_dispatches_job(): void
    {
        Bus::fake();

        $response = $this->from(route('clipper.index'))->post(route('clip.store'), [
            'youtube_url' => 'https://www.youtube.com/watch?v=abc123',
            'start_time' => 15,
            'duration' => 45,
            'quality_height' => 720,
            'crop_mode' => 'left',
            'watermark_enabled' => '1',
            'watermark_position' => 'bottom-left',
            'watermark_opacity' => 65,
            'signature_enabled' => '1',
            'polish_enabled' => '1',
        ]);

        $response->assertRedirect(route('clipper.index'));
        $response->assertSessionHas('status');

        $clip = Clip::first();

        $this->assertNotNull($clip);
        $this->assertSame(Clip::STATUS_QUEUED, $clip->status);
        $this->assertSame(15, $clip->start_time);
        $this->assertSame(45, $clip->duration);
        $this->assertSame(720, $clip->quality_height);
        $this->assertSame('left', $clip->crop_mode);
        $this->assertTrue($clip->watermark_enabled);
        $this->assertSame('bottom-left', $clip->watermark_position);
        $this->assertSame(65, $clip->watermark_opacity);
        $this->assertTrue($clip->signature_enabled);
        $this->assertTrue($clip->polish_enabled);

        Bus::assertDispatched(ProcessVideoClipper::class, fn (ProcessVideoClipper $job): bool => $job->clipId === $clip->id);
    }

    public function test_done_clip_can_be_downloaded(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('clips/result.mp4', 'fake-video');

        $clip = Clip::create([
            'youtube_url' => 'https://youtu.be/abc123',
            'status' => Clip::STATUS_DONE,
            'progress' => 100,
            'output_disk' => 'public',
            'output_path' => 'clips/result.mp4',
        ]);

        $this->get(route('clips.download', $clip))->assertOk();
    }

    public function test_cleanup_removes_old_clips_and_output_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('clips/old.mp4', 'old-video');
        Storage::disk('public')->put('clips/new.mp4', 'new-video');

        $oldClip = Clip::create([
            'youtube_url' => 'https://youtu.be/old123',
            'status' => Clip::STATUS_DONE,
            'progress' => 100,
            'output_disk' => 'public',
            'output_path' => 'clips/old.mp4',
        ]);

        $oldClip->forceFill([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ])->save();

        $newClip = Clip::create([
            'youtube_url' => 'https://youtu.be/new123',
            'status' => Clip::STATUS_DONE,
            'progress' => 100,
            'output_disk' => 'public',
            'output_path' => 'clips/new.mp4',
        ]);

        $this->artisan('clips:cleanup', ['--days' => 7])->assertExitCode(0);

        Storage::disk('public')->assertMissing('clips/old.mp4');
        Storage::disk('public')->assertExists('clips/new.mp4');
        $this->assertDatabaseMissing('clips', ['id' => $oldClip->id]);
        $this->assertDatabaseHas('clips', ['id' => $newClip->id]);
    }
}
