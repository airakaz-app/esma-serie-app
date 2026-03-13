<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seriesInfo->title ?: 'Série' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="container py-4 py-lg-5">
    <a href="{{ route('series-infos.index') }}" class="text-decoration-none text-info">← Retour aux séries</a>

    <section class="card border-0 bg-dark text-light mt-3 shadow-sm">
        <div class="row g-0">
            <div class="col-md-4 bg-black">
                @if ($seriesInfo->cover_image_url)
                    <img src="{{ $seriesInfo->cover_image_url }}" alt="Affiche {{ $seriesInfo->title ?: 'série' }}" class="img-fluid w-100 h-100 object-fit-cover">
                @else
                    <div class="d-flex align-items-center justify-content-center h-100 text-secondary p-5">Pas d'image</div>
                @endif
            </div>
            <div class="col-md-8">
                <div class="card-body">
                    <h1 class="h3 card-title">{{ $seriesInfo->title ?: 'Sans titre' }}</h1>
                    <p class="card-text text-secondary">{{ $seriesInfo->episodes->count() }} épisode(s) lié(s)</p>
                    @if ($seriesInfo->story)
                        <p class="card-text">{{ $seriesInfo->story }}</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="mt-4">
        <h2 class="h4 mb-3">Épisodes</h2>

        <div class="row g-4 row-cols-2 row-cols-lg-3 row-cols-xxl-4">
            @forelse ($seriesInfo->episodes as $episode)
                @php
                    $playableServer = $episode->servers->first(fn ($server): bool => (string) $server->final_url !== '');
                    $playableUrl = $playableServer?->final_url;
                @endphp

                <div class="col">
                    @if ($playableUrl)
                        <a href="{{ $playableUrl }}" target="_blank" class="text-decoration-none d-block h-100">
                    @endif

                    <article class="card h-100 border-0 shadow-sm bg-dark text-light {{ $playableUrl ? 'cursor-pointer' : '' }}">
                        <div class="ratio ratio-16x9 bg-black position-relative">
                            @if ($seriesInfo->cover_image_url)
                                <img
                                    src="{{ $seriesInfo->cover_image_url }}"
                                    alt="Image série {{ $seriesInfo->title ?: 'série' }}"
                                    class="w-100 h-100 object-fit-cover"
                                    loading="lazy"
                                >
                            @else
                                <div class="d-flex align-items-center justify-content-center text-secondary">Pas d'image</div>
                            @endif

                            @if ($episode->episode_number)
                                <span class="badge text-bg-danger position-absolute top-0 start-0 m-2 px-3 py-2">
                                    Épisode {{ $episode->episode_number }}
                                </span>
                            @endif
                        </div>

                        <div class="card-body">
                            <h3 class="h6 card-title mb-2">{{ $episode->title }}</h3>
                            <p class="small mb-0 {{ $playableUrl ? 'text-info' : 'text-secondary' }}">
                                {{ $playableUrl ? 'Lire maintenant' : 'Lien final indisponible' }}
                            </p>
                        </div>
                    </article>

                    @if ($playableUrl)
                        </a>
                    @endif
                </div>
            @empty
                <p class="rounded-3 border border-secondary-subtle bg-dark-subtle p-4 text-secondary">Aucun épisode lié à cette série.</p>
            @endforelse
        </div>
    </section>
</div>
</body>
</html>
