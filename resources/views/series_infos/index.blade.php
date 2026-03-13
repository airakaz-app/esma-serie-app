<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Séries</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="mx-auto max-w-5xl p-6">
    <h1 class="text-3xl font-bold mb-6">Séries</h1>

    <div class="space-y-4">
        @forelse ($seriesInfos as $seriesInfo)
            <a href="{{ route('series-infos.show', $seriesInfo) }}" class="block rounded-xl border border-slate-800 bg-slate-900/60 p-4 hover:border-indigo-400 transition">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">{{ $seriesInfo->title ?: 'Sans titre' }}</h2>
                        <p class="text-sm text-slate-400">{{ $seriesInfo->episodes_count }} épisode(s)</p>
                    </div>
                    <span class="text-indigo-300 text-sm">Voir épisodes →</span>
                </div>
            </a>
        @empty
            <p class="text-slate-400">Aucune série trouvée.</p>
        @endforelse
    </div>
</div>
</body>
</html>
