<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Séries</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="mx-auto max-w-7xl p-6 lg:p-8">
    <header class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight">Séries disponibles</h1>
        <p class="mt-2 text-sm text-slate-400">Cliquez sur une carte pour afficher les épisodes liés.</p>
    </header>

    @if ($seriesInfos->isEmpty())
        <p class="rounded-xl border border-slate-800 bg-slate-900/60 p-6 text-slate-400">Aucune série trouvée.</p>
    @else
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($seriesInfos as $seriesInfo)
                <a
                    href="{{ route('series-infos.show', $seriesInfo) }}"
                    class="group overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/70 shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-400/80 hover:shadow-indigo-950/30"
                >
                    <div class="aspect-[16/9] bg-slate-900">
                        @if ($seriesInfo->cover_image_url)
                            <img
                                src="{{ $seriesInfo->cover_image_url }}"
                                alt="Affiche de {{ $seriesInfo->title ?: 'série' }}"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            >
                        @else
                            <div class="flex h-full w-full items-center justify-center text-slate-500">Pas d'image</div>
                        @endif
                    </div>

                    <div class="space-y-3 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <h2 class="line-clamp-2 text-lg font-semibold leading-tight">{{ $seriesInfo->title ?: 'Sans titre' }}</h2>
                            <span class="whitespace-nowrap rounded-full bg-indigo-500/15 px-2.5 py-1 text-xs font-medium text-indigo-300">
                                {{ $seriesInfo->episodes_count }} épisode(s)
                            </span>
                        </div>

                        @if ($seriesInfo->story)
                            <p class="line-clamp-3 text-sm text-slate-400">{{ $seriesInfo->story }}</p>
                        @endif

                        <div class="space-y-2 text-xs text-slate-400">
                            @if ($seriesInfo->episodes_min_episode_number || $seriesInfo->episodes_max_episode_number)
                                <p>
                                    Plage d'épisodes:
                                    <span class="text-slate-300">
                                        {{ $seriesInfo->episodes_min_episode_number ?? '?' }} → {{ $seriesInfo->episodes_max_episode_number ?? '?' }}
                                    </span>
                                </p>
                            @endif

                            @if (is_array($seriesInfo->categories) && count($seriesInfo->categories) > 0)
                                <p class="line-clamp-1">Catégories: <span class="text-slate-300">{{ implode(', ', $seriesInfo->categories) }}</span></p>
                            @endif

                            @if (is_array($seriesInfo->actors) && count($seriesInfo->actors) > 0)
                                <p class="line-clamp-1">Acteurs: <span class="text-slate-300">{{ implode(', ', $seriesInfo->actors) }}</span></p>
                            @endif
                        </div>

                        <p class="pt-1 text-sm text-indigo-300 transition group-hover:text-indigo-200">Voir les épisodes →</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
</body>
</html>
