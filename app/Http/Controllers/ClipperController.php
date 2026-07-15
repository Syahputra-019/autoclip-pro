<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoClipper;
use App\Models\Clip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ClipperController extends Controller
{
    public function index()
    {
        $clips = Clip::query()
            ->latest()
            ->limit(24)
            ->get();

        $stats = [
            'queued' => Clip::where('status', Clip::STATUS_QUEUED)->count(),
            'processing' => Clip::where('status', Clip::STATUS_PROCESSING)->count(),
            'done' => Clip::where('status', Clip::STATUS_DONE)->count(),
            'failed' => Clip::where('status', Clip::STATUS_FAILED)->count(),
        ];

        return view('dashboard', compact('clips', 'stats'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'youtube_url' => [
                'required',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $host = strtolower((string) parse_url((string) $value, PHP_URL_HOST));

                    if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be'], true)
                        && ! str_ends_with($host, '.youtube.com')) {
                        $fail('Masukkan URL YouTube yang valid.');
                    }
                },
            ],
            'start_time' => ['nullable', 'integer', 'min:0', 'max:21600'],
            'duration' => ['nullable', 'integer', 'min:5', 'max:180'],
            'quality_height' => ['nullable', 'integer', Rule::in([480, 720, 1080])],
            'crop_mode' => ['nullable', Rule::in(['left', 'center', 'right', 'pan_center', 'fill'])],
            'watermark_enabled' => ['nullable', 'boolean'],
            'watermark_position' => ['nullable', Rule::in(['top-left', 'top-right', 'bottom-left', 'bottom-right'])],
            'watermark_opacity' => ['nullable', 'integer', 'min:20', 'max:90'],
            'signature_enabled' => ['nullable', 'boolean'],
            'polish_enabled' => ['nullable', 'boolean'],
        ]);

        $clip = Clip::create([
            'youtube_url' => $validated['youtube_url'],
            'status' => Clip::STATUS_QUEUED,
            'progress' => 0,
            'start_time' => (int) ($validated['start_time'] ?? 10),
            'duration' => (int) ($validated['duration'] ?? 60),
            'quality_height' => (int) ($validated['quality_height'] ?? 1080),
            'crop_mode' => $validated['crop_mode'] ?? 'pan_center',
            'watermark_enabled' => $request->boolean('watermark_enabled', true),
            'watermark_position' => $validated['watermark_position'] ?? 'top-right',
            'watermark_opacity' => (int) ($validated['watermark_opacity'] ?? 55),
            'signature_enabled' => $request->boolean('signature_enabled', true),
            'polish_enabled' => $request->boolean('polish_enabled', true),
        ]);

        ProcessVideoClipper::dispatch($clip->id);

        return back()->with('status', 'Clip masuk antrean. Video YouTube tidak disimpan mentah; hasil akhir dibuat dengan brand kit yang kamu pilih.');
    }

    public function stream(Clip $clip)
    {
        $path = $this->validatedOutputPath($clip);

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="'.$clip->downloadName().'"',
        ]);
    }

    public function download(Clip $clip)
    {
        $path = $this->validatedOutputPath($clip);

        return response()->download($path, $clip->downloadName(), [
            'Content-Type' => 'video/mp4',
        ]);
    }

    public function destroy(Clip $clip)
    {
        if ($clip->isProcessing()) {
            return back()->withErrors(['clip' => 'Clip yang sedang diproses belum bisa dihapus.']);
        }

        if ($clip->output_path && Storage::disk($clip->output_disk)->exists($clip->output_path)) {
            Storage::disk($clip->output_disk)->delete($clip->output_path);
        }

        $clip->delete();

        return back()->with('status', 'Clip dan file hasilnya sudah dihapus.');
    }

    private function validatedOutputPath(Clip $clip): string
    {
        abort_unless($clip->isDone(), 404);
        abort_unless(Storage::disk($clip->output_disk)->exists($clip->output_path), 404);

        return Storage::disk($clip->output_disk)->path($clip->output_path);
    }
}
