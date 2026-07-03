<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoClip Pro - Laravel 13</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between font-sans">

    <nav class="border-b border-slate-800 bg-slate-900/50 backdrop-blur-md px-6 py-4 flex justify-between items-center">
        <span class="text-xl font-bold bg-linear-to-r from-violet-400 to-fuchsia-500 bg-clip-text text-transparent">🎬 AutoClip Pro</span>
    </nav>

    <main class="max-w-2xl mx-auto w-full px-4 py-12 grow flex flex-col justify-center">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-extrabold tracking-tight mb-3 bg-linear-to-b from-white to-slate-400 bg-clip-text text-transparent">
                YouTube to Vertical Short Converter
            </h1>
            <p class="text-slate-400">Otomatis download, kuatkan audio, dan crop ke format 9:16.</p>
        </div>

        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 backdrop-blur-lg shadow-xl">
            @if (session('status'))
                <div class="mb-4 bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 p-4 rounded-xl text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <form action="{{ route('clip.store') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-400 mb-2">URL Video YouTube</label>
                    <input type="url" name="youtube_url" required placeholder="https://www.youtube.com/watch?v=..." 
                        class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-200 placeholder-slate-600 focus:outline-none focus:border-violet-500 transition-all">
                </div>

                <button type="submit" 
                    class="w-full bg-linear-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 text-white font-semibold py-3 px-4 rounded-xl shadow-lg shadow-violet-600/20 hover:scale-[1.01] transition-all cursor-pointer text-center block">
                    Gass Potong Otomatis 🚀
                </button>
            </form>
        </div>
    </main>

    <footer class="border-t border-slate-900 py-4 text-center text-xs text-slate-600">
        &copy; 2026 AutoClip Pro. All rights reserved.
    </footer>

</body>
</html>