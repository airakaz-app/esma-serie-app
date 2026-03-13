<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seriesInfo->title ?: 'Série' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="mx-auto max-w-7xl p-6 lg:p-8">
    <a href="{{ route('series-infos.index') }}" class="inline-block text-sm text-indigo-300 hover:text-indigo-200">← Retour aux séries</a>

    <section class="mt-4 overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70">
        <div class="grid gap-0 md:grid-cols-3">
            <div class="aspect-[16/9] md:aspect-auto md:h-full bg-slate-900">
                @if ($seriesInfo->cover_image_url)
                    <img src="{{ $seriesInfo->cover_image_url }}" alt="Affiche {{ $seriesInfo->title ?: 'série' }}" class="h-full w-full object-cover">
                @else
                    <div class="flex h-full w-full items-center justify-center text-slate-500">Pas d'image</div>
                @endif
            </div>
            <div class="space-y-3 p-5 md:col-span-2">
                <h1 class="text-2xl font-bold">{{ $seriesInfo->title ?: 'Sans titre' }}</h1>
                <p class="text-sm text-slate-400">{{ $seriesInfo->episodes->count() }} épisode(s) lié(s)</p>
                @if ($seriesInfo->story)
                    <p class="text-sm leading-6 text-slate-300">{{ $seriesInfo->story }}</p>
                @endif
            </div>
        </div>
    </section>

    <section class="mt-6">
        <h2 class="mb-3 text-lg font-semibold">Épisodes</h2>

        <div class="grid gap-3">
            @forelse ($seriesInfo->episodes as $episode)
                <article class="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="font-semibold text-slate-100">
                            @if ($episode->episode_number)
                                Épisode {{ $episode->episode_number }} —
                            @endif
                            {{ $episode->title }}
                        </h3>
                        <a href="{{ $episode->page_url }}" target="_blank" class="text-xs text-indigo-300 hover:text-indigo-200">Ouvrir</a>
                    </div>
                    <p class="mt-2 break-all text-xs text-slate-400">{{ $episode->page_url }}</p>
                </article>
            @empty
                <p class="rounded-xl border border-slate-800 bg-slate-900/60 p-4 text-slate-400">Aucun épisode lié à cette série.</p>
            @endforelse
        </div>
    </section>
</div>
</body>
</html>
