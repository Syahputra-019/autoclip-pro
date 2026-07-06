<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @if ($clips->contains(fn ($clip) => in_array($clip->status, ['queued', 'processing'], true)))
        <meta http-equiv="refresh" content="8">
    @endif
    <title>AutoClip Pro</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 font-sans">
    <header class="border-b border-zinc-800 bg-zinc-950/90">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-400">AutoClip Pro</p>
                <h1 class="mt-1 text-xl font-semibold tracking-tight text-white">YouTube Short Converter</h1>
            </div>
            <div class="hidden text-right text-sm text-zinc-400 sm:block">
                <p class="text-zinc-500">Output final tersimpan di storage publik aplikasi</p>
            </div>
        </div>
    </header>

    <main class="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[380px_1fr] lg:px-8">
        <section class="h-fit rounded-lg border border-zinc-800 bg-zinc-900/70 p-5 shadow-2xl shadow-black/20">
            <div class="mb-5">
                <h2 class="text-base font-semibold text-white">Buat Clip Baru</h2>
                <p class="mt-1 text-sm text-zinc-400">Pilih bagian video dan hasilkan format vertikal 9:16.</p>
            </div>

            @if (session('status'))
                <div class="mb-4 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-md border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('clip.store') }}" method="POST" class="space-y-4">
                @csrf

                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-zinc-300">URL YouTube</span>
                    <input
                        type="url"
                        name="youtube_url"
                        required
                        value="{{ old('youtube_url') }}"
                        placeholder="https://www.youtube.com/watch?v=..."
                        class="w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-3 text-sm text-zinc-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
                    >
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-zinc-300">Mulai Detik</span>
                        <input
                            type="number"
                            name="start_time"
                            min="0"
                            max="21600"
                            value="{{ old('start_time', 10) }}"
                            class="w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-3 text-sm text-zinc-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
                        >
                    </label>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-zinc-300">Durasi</span>
                        <input
                            type="number"
                            name="duration"
                            min="5"
                            max="180"
                            value="{{ old('duration', 60) }}"
                            class="w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-3 text-sm text-zinc-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
                        >
                    </label>
                </div>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-zinc-300">Kualitas Source</span>
                    <select
                        name="quality_height"
                        class="w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-3 text-sm text-zinc-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
                    >
                        <option value="1080" @selected(old('quality_height', 1080) == 1080)>Maks 1080p</option>
                        <option value="720" @selected(old('quality_height') == 720)>Maks 720p</option>
                        <option value="480" @selected(old('quality_height') == 480)>Maks 480p</option>
                    </select>
                </label>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-zinc-300">Fokus Crop</legend>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach (['left' => 'Kiri', 'center' => 'Tengah', 'right' => 'Kanan'] as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="crop_mode" value="{{ $value }}" class="peer sr-only" @checked(old('crop_mode', 'center') === $value)>
                                <span class="block rounded-md border border-zinc-700 bg-zinc-950 px-3 py-2 text-center text-sm text-zinc-300 transition peer-checked:border-cyan-500 peer-checked:bg-cyan-500/10 peer-checked:text-cyan-100">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <fieldset class="rounded-lg border border-zinc-800 bg-zinc-950/60 p-4">
                    <legend class="px-1 text-sm font-semibold text-cyan-200">Brand Kit</legend>

                    <div class="space-y-3">
                        <label class="flex items-start gap-3">
                            <input type="hidden" name="watermark_enabled" value="0">
                            <input
                                type="checkbox"
                                name="watermark_enabled"
                                value="1"
                                class="mt-1 size-4 rounded border-zinc-600 bg-zinc-950 text-cyan-500 focus:ring-cyan-500"
                                @checked((bool) old('watermark_enabled', true))
                            >
                            <span>
                                <span class="block text-sm font-medium text-zinc-200">Watermark FRAMEDROP</span>
                                <span class="block text-xs text-zinc-500">Logo kecil transparan untuk identitas akun.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3">
                            <input type="hidden" name="signature_enabled" value="0">
                            <input
                                type="checkbox"
                                name="signature_enabled"
                                value="1"
                                class="mt-1 size-4 rounded border-zinc-600 bg-zinc-950 text-cyan-500 focus:ring-cyan-500"
                                @checked((bool) old('signature_enabled', true))
                            >
                            <span>
                                <span class="block text-sm font-medium text-zinc-200">Outro Signature</span>
                                <span class="block text-xs text-zinc-500">Logo besar muncul halus di akhir clip.</span>
                            </span>
                        </label>

                        <label class="flex items-start gap-3">
                            <input type="hidden" name="polish_enabled" value="0">
                            <input
                                type="checkbox"
                                name="polish_enabled"
                                value="1"
                                class="mt-1 size-4 rounded border-zinc-600 bg-zinc-950 text-cyan-500 focus:ring-cyan-500"
                                @checked((bool) old('polish_enabled', true))
                            >
                            <span>
                                <span class="block text-sm font-medium text-zinc-200">Upload Polish</span>
                                <span class="block text-xs text-zinc-500">Contrast, saturation, sharpen, 30fps, dan yuv420p.</span>
                            </span>
                        </label>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-zinc-300">Posisi Logo</span>
                            <select
                                name="watermark_position"
                                class="w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-3 text-sm text-zinc-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
                            >
                                <option value="top-right" @selected(old('watermark_position', 'top-right') === 'top-right')>Atas kanan</option>
                                <option value="top-left" @selected(old('watermark_position') === 'top-left')>Atas kiri</option>
                                <option value="bottom-right" @selected(old('watermark_position') === 'bottom-right')>Bawah kanan</option>
                                <option value="bottom-left" @selected(old('watermark_position') === 'bottom-left')>Bawah kiri</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-zinc-300">Opacity</span>
                            <input
                                type="number"
                                name="watermark_opacity"
                                min="20"
                                max="90"
                                value="{{ old('watermark_opacity', 55) }}"
                                class="w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-3 text-sm text-zinc-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
                            >
                        </label>
                    </div>
                </fieldset>

                <button
                    type="submit"
                    class="w-full rounded-md bg-cyan-500 px-4 py-3 text-sm font-semibold text-zinc-950 transition hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-300"
                >
                    Proses Clip
                </button>
            </form>
        </section>

        <section class="space-y-5">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                @foreach ([
                    'queued' => ['label' => 'Antrean', 'class' => 'border-amber-500/30 bg-amber-500/10 text-amber-100'],
                    'processing' => ['label' => 'Proses', 'class' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-100'],
                    'done' => ['label' => 'Selesai', 'class' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100'],
                    'failed' => ['label' => 'Gagal', 'class' => 'border-red-500/30 bg-red-500/10 text-red-100'],
                ] as $key => $item)
                    <div class="rounded-lg border {{ $item['class'] }} p-4">
                        <p class="text-xs font-medium uppercase tracking-[0.16em] opacity-75">{{ $item['label'] }}</p>
                        <p class="mt-2 text-3xl font-semibold">{{ $stats[$key] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="rounded-lg border border-zinc-800 bg-zinc-900/50">
                <div class="flex items-center justify-between border-b border-zinc-800 px-5 py-4">
                    <div>
                        <h2 class="text-base font-semibold text-white">Riwayat Clip</h2>
                        <p class="mt-1 text-sm text-zinc-400">24 clip terbaru dari queue aplikasi.</p>
                    </div>
                </div>

                <div class="divide-y divide-zinc-800">
                    @forelse ($clips as $clip)
                        @php
                            $statusStyle = [
                                'queued' => 'border-amber-500/30 bg-amber-500/10 text-amber-100',
                                'processing' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-100',
                                'done' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100',
                                'failed' => 'border-red-500/30 bg-red-500/10 text-red-100',
                            ][$clip->status] ?? 'border-zinc-600 bg-zinc-800 text-zinc-200';
                        @endphp

                        <article class="grid gap-4 p-5 xl:grid-cols-[180px_1fr]">
                            <div class="overflow-hidden rounded-md border border-zinc-800 bg-zinc-950">
                                @if ($clip->isDone())
                                    <video controls preload="metadata" class="aspect-[9/16] h-full w-full bg-black object-cover">
                                        <source src="{{ route('clips.stream', $clip) }}" type="video/mp4">
                                    </video>
                                @else
                                    <div class="flex aspect-[9/16] items-center justify-center px-4 text-center text-sm text-zinc-500">
                                        {{ ucfirst($clip->status) }}
                                    </div>
                                @endif
                            </div>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="truncate text-base font-semibold text-white">{{ $clip->displayTitle() }}</h3>
                                        <a href="{{ $clip->youtube_url }}" target="_blank" rel="noreferrer" class="mt-1 block truncate text-sm text-cyan-300 hover:text-cyan-200">
                                            {{ $clip->shortUrl() }}
                                        </a>
                                    </div>

                                    <span class="rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-[0.14em] {{ $statusStyle }}">
                                        {{ $clip->status }}
                                    </span>
                                </div>

                                <div class="mt-4 h-2 overflow-hidden rounded-full bg-zinc-800">
                                    <div class="h-full rounded-full bg-cyan-400 transition-all" style="width: {{ $clip->progress }}%"></div>
                                </div>

                                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm text-zinc-400 md:grid-cols-4">
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.14em] text-zinc-500">Mulai</dt>
                                        <dd class="mt-1 text-zinc-200">{{ $clip->start_time }}s</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.14em] text-zinc-500">Durasi</dt>
                                        <dd class="mt-1 text-zinc-200">{{ $clip->duration }}s</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.14em] text-zinc-500">Source</dt>
                                        <dd class="mt-1 text-zinc-200">{{ $clip->quality_height }}p</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.14em] text-zinc-500">Crop</dt>
                                        <dd class="mt-1 text-zinc-200">{{ ucfirst($clip->crop_mode) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.14em] text-zinc-500">Brand</dt>
                                        <dd class="mt-1 text-zinc-200">{{ $clip->watermark_enabled || $clip->signature_enabled ? 'On' : 'Off' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs uppercase tracking-[0.14em] text-zinc-500">Logo</dt>
                                        <dd class="mt-1 text-zinc-200">{{ $clip->watermark_position }}</dd>
                                    </div>
                                </dl>

                                @if ($clip->errorSummary())
                                    <p class="mt-4 rounded-md border border-red-500/20 bg-red-500/10 px-3 py-2 text-sm text-red-200">
                                        {{ $clip->errorSummary() }}
                                    </p>
                                @endif

                                <div class="mt-5 flex flex-wrap gap-2">
                                    @if ($clip->isDone())
                                        <a
                                            href="{{ route('clips.download', $clip) }}"
                                            class="rounded-md bg-emerald-500 px-3 py-2 text-sm font-semibold text-zinc-950 transition hover:bg-emerald-400"
                                        >
                                            Download
                                        </a>
                                    @endif

                                    @unless ($clip->isProcessing())
                                        <form action="{{ route('clips.destroy', $clip) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="rounded-md border border-zinc-700 px-3 py-2 text-sm font-semibold text-zinc-300 transition hover:border-red-500/60 hover:text-red-200"
                                            >
                                                Hapus
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="px-5 py-16 text-center text-sm text-zinc-500">
                            Belum ada clip.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>
    </main>
</body>
</html>
