<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seriesInfo->title ?: 'Série' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="mx-auto max-w-5xl p-6">
    <a href="{{ route('series-infos.index') }}" class="inline-block mb-6 text-indigo-300 hover:text-indigo-200">← Retour aux séries</a>

    <h1 class="text-3xl font-bold">{{ $seriesInfo->title ?: 'Sans titre' }}</h1>

    <div class="mt-6 space-y-3">
        @forelse ($seriesInfo->episodes as $episode)
            <article class="rounded-lg border border-slate-800 bg-slate-900/60 p-4">
                <h2 class="font-semibold">
                    @if ($episode->episode_number)
                        Épisode {{ $episode->episode_number }} -
                    @endif
                    {{ $episode->title }}
                </h2>
                <a href="{{ $episode->page_url }}" target="_blank" class="text-sm text-indigo-300 hover:text-indigo-200">{{ $episode->page_url }}</a>
            </article>
        @empty
            <p class="text-slate-400">Aucun épisode lié à cette série.</p>
        @endforelse
    </div>
</div>
</body>
</html>
