<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Séries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="container py-4 py-lg-5">
    <header class="mb-4 mb-lg-5">
        <h1 class="display-6 fw-bold mb-2">Séries disponibles</h1>
        <p class="text-secondary mb-0">Cliquez sur une carte pour afficher les épisodes liés.</p>
    </header>

    @if ($seriesInfos->isEmpty())
        <p class="rounded-3 border border-secondary-subtle bg-dark-subtle p-4 text-secondary">Aucune série trouvée.</p>
    @else
        <div class="row g-4 row-cols-2 row-cols-lg-3 row-cols-xxl-4">
            @foreach ($seriesInfos as $seriesInfo)
                @php
                    $categories = collect($seriesInfo->categories)
                        ->flatten(1)
                        ->filter(fn ($value): bool => is_scalar($value) && (string) $value !== '')
                        ->map(fn ($value): string => (string) $value)
                        ->values();

                    $actors = collect($seriesInfo->actors)
                        ->flatten(1)
                        ->filter(fn ($value): bool => is_scalar($value) && (string) $value !== '')
                        ->map(fn ($value): string => (string) $value)
                        ->values();
                @endphp

                <div class="col">
                    <a href="{{ route('series-infos.show', $seriesInfo) }}" class="text-decoration-none d-block h-100">
                        <article class="card h-100 border-0 shadow-sm bg-dark text-light">
                            <div class="ratio ratio-16x9 bg-black">
                                @if ($seriesInfo->cover_image_url)
                                    <img
                                        src="{{ $seriesInfo->cover_image_url }}"
                                        alt="Affiche de {{ $seriesInfo->title ?: 'série' }}"
                                        class="w-100 h-100 object-fit-cover"
                                        loading="lazy"
                                    >
                                @else
                                    <div class="d-flex align-items-center justify-content-center text-secondary">Pas d'image</div>
                                @endif
                            </div>

                            <div class="card-body d-flex flex-column gap-2">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <h2 class="h5 card-title mb-0">{{ $seriesInfo->title ?: 'Sans titre' }}</h2>
                                    <span class="badge text-bg-primary">{{ $seriesInfo->episodes_count }} épisode(s)</span>
                                </div>

                                @if ($seriesInfo->story)
                                    <p class="card-text small text-secondary mb-1">{{ \Illuminate\Support\Str::limit($seriesInfo->story, 130) }}</p>
                                @endif

                                <div class="small text-secondary mt-auto">
                                    @if ($seriesInfo->episodes_min_episode_number || $seriesInfo->episodes_max_episode_number)
                                        <p class="mb-1">
                                            Plage d'épisodes:
                                            <span class="text-light">{{ $seriesInfo->episodes_min_episode_number ?? '?' }} → {{ $seriesInfo->episodes_max_episode_number ?? '?' }}</span>
                                        </p>
                                    @endif

                                    @if ($categories->isNotEmpty())
                                        <p class="mb-1">Catégories: <span class="text-light">{{ $categories->implode(', ') }}</span></p>
                                    @endif

                                    @if ($actors->isNotEmpty())
                                        <p class="mb-0">Acteurs: <span class="text-light">{{ $actors->implode(', ') }}</span></p>
                                    @endif
                                </div>
                            </div>
                        </article>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
</body>
</html>
