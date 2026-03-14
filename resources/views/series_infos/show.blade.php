<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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

    @php
        $episodesTotal = $seriesInfo->episodes->count();
        $episodesDone = $seriesInfo->episodes->where('status', \App\Models\Episode::STATUS_DONE)->count();
        $episodesInProgress = $seriesInfo->episodes->where('status', \App\Models\Episode::STATUS_IN_PROGRESS)->count();
        $episodesPending = $seriesInfo->episodes->where('status', \App\Models\Episode::STATUS_PENDING)->count();
        $isScrapingInProgress = ($episodesInProgress + $episodesPending) > 0;
        $progressPercent = $episodesTotal > 0 ? (int) round(($episodesDone / $episodesTotal) * 100) : 0;
    @endphp

    <section class="mt-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h4 mb-0">Épisodes</h2>

            @php
                $refreshListPageUrl = $seriesInfo->series_page_url ?: $seriesInfo->source_episode_page_url;
            @endphp

            @if ($refreshListPageUrl)
                <button
                    type="button"
                    id="refreshEpisodesButton"
                    data-list-page-url="{{ $refreshListPageUrl }}"
                    class="btn btn-outline-info btn-sm"
                >
                    Refresh épisodes
                </button>
            @endif
        </div>

        <div id="refreshEpisodesError" class="alert alert-danger d-none"></div>

        @if ($isScrapingInProgress)
            <div class="alert alert-info">
                <div class="d-flex justify-content-between small mb-2">
                    <span>Récupération des liens en cours...</span>
                    <span>{{ $progressPercent }}%</span>
                </div>
                <div class="progress" role="progressbar" aria-label="Progression de récupération">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: {{ $progressPercent }}%"></div>
                </div>
            </div>
        @endif

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
                                <span class="badge position-absolute top-0 start-0 m-2 px-3 py-2">
                                    <div class="bg-danger p-2">Épisode {{ $episode->episode_number }}</div>
                                </span>
                            @endif
                        </div>

                        <div class="card-body">
                            <h3 class="h6 card-title mb-2">{{ $episode->title }}</h3>
                            <p class="small mb-0 {{ $playableUrl ? 'text-info' : 'text-secondary' }}">
                                @if ($playableUrl)
                                    Lire maintenant
                                @elseif (in_array($episode->status, [\App\Models\Episode::STATUS_PENDING, \App\Models\Episode::STATUS_IN_PROGRESS], true))
                                    Récupération en cours...
                                @elseif ($episode->status === \App\Models\Episode::STATUS_ERROR)
                                    Erreur de récupération
                                @else
                                    Lien final indisponible
                                @endif
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

@if ($isScrapingInProgress)
    <script>
        window.setTimeout(() => {
            window.location.reload();
        }, 10000);
    </script>
@endif

@if ($refreshListPageUrl)
    <script>
        const refreshEpisodesButton = document.getElementById('refreshEpisodesButton');
        const refreshEpisodesError = document.getElementById('refreshEpisodesError');

        const setRefreshButtonState = (isLoading) => {
            refreshEpisodesButton.disabled = isLoading;
            refreshEpisodesButton.textContent = isLoading ? 'Refresh en cours...' : 'Refresh épisodes';
        };

        const startPolling = (trackingKey) => {
            const poll = async () => {
                const response = await fetch(`{{ route('series-infos.scrape-status', ['trackingKey' => '__TRACKING_KEY__']) }}`.replace('__TRACKING_KEY__', trackingKey), {
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok && response.status !== 404) {
                    throw new Error('Impossible de suivre la progression du refresh.');
                }

                if (response.status === 404) {
                    return;
                }

                const data = await response.json();

                if (data.state === 'error') {
                    throw new Error(data.lastError ?? data.message ?? 'Erreur pendant le refresh.');
                }

                if (data.state === 'completed') {
                    window.location.reload();
                }
            };

            const intervalId = window.setInterval(() => {
                poll().catch((error) => {
                    window.clearInterval(intervalId);
                    setRefreshButtonState(false);
                    refreshEpisodesError.textContent = error.message;
                    refreshEpisodesError.classList.remove('d-none');
                });
            }, 2500);

            poll().catch((error) => {
                window.clearInterval(intervalId);
                setRefreshButtonState(false);
                refreshEpisodesError.textContent = error.message;
                refreshEpisodesError.classList.remove('d-none');
            });
        };

        refreshEpisodesButton.addEventListener('click', async () => {
            refreshEpisodesError.textContent = '';
            refreshEpisodesError.classList.add('d-none');
            setRefreshButtonState(true);

            const formData = new FormData();
            formData.set('list_page_url', refreshEpisodesButton.dataset.listPageUrl ?? '');
            formData.set('retry_errors', '1');

            try {
                const response = await fetch('{{ route('series-infos.scrape') }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: formData,
                });

                const data = await response.json();

                if (!response.ok) {
                    const firstError = data?.errors?.list_page_url?.[0] ?? data?.message ?? 'Impossible de lancer le refresh.';
                    throw new Error(firstError);
                }

                startPolling(data.trackingKey);
            } catch (error) {
                setRefreshButtonState(false);
                refreshEpisodesError.textContent = error.message;
                refreshEpisodesError.classList.remove('d-none');
            }
        });
    </script>
@endif
</body>
</html>
