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

    @if (session('status'))
        <div class="alert alert-success mt-3">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger mt-3 mb-0">{{ $errors->first() }}</div>
    @endif

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
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <h1 class="h3 card-title">{{ $seriesInfo->title ?: 'Sans titre' }}</h1>
                            <p class="card-text text-secondary mb-0">{{ $seriesInfo->episodes->count() }} épisode(s) lié(s)</p>
                        </div>

                        <form method="POST" action="{{ route('series-infos.destroy', $seriesInfo) }}" onsubmit="return confirm('Supprimer cette série et tous ses épisodes ?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">Supprimer la série</button>
                        </form>
                    </div>

                    @if ($seriesInfo->story)
                        <p class="card-text mt-3">{{ $seriesInfo->story }}</p>
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

        @if ($seriesInfo->episodes->isNotEmpty())
            <form method="POST" action="{{ route('series-infos.episodes.bulk-destroy', $seriesInfo) }}" id="bulkDeleteEpisodesForm" class="mb-2 d-flex flex-wrap align-items-center gap-2" onsubmit="return window.confirmBulkDeleteEpisodes();">
                @csrf
                @method('DELETE')
                <div class="form-check m-0">
                    <input class="form-check-input" type="checkbox" id="selectAllEpisodes">
                    <label class="form-check-label" for="selectAllEpisodes">Tout sélectionner</label>
                </div>
                <button type="submit" class="btn btn-outline-danger btn-sm" id="bulkDeleteEpisodesButton" disabled>
                    Supprimer la sélection
                </button>
                <button type="button" class="btn btn-outline-success btn-sm" id="bulkDownloadEpisodesButton" disabled>
                    Télécharger la sélection
                </button>
                <button type="button" class="btn btn-success btn-sm" id="downloadSeriesButton">
                    Télécharger toute la série
                </button>
            </form>
            <p class="small text-secondary mb-3" id="bulkDownloadStatus">
                Sélectionnez un ou plusieurs épisodes pour lancer un téléchargement massif fiable (ou toute la série).
            </p>
        @endif

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
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div class="form-check">
                                    <input
                                        class="form-check-input episode-checkbox"
                                        type="checkbox"
                                        name="episode_ids[]"
                                        value="{{ $episode->id }}"
                                        form="bulkDeleteEpisodesForm"
                                        id="episode-{{ $episode->id }}"
                                        data-episode-title="{{ $episode->title }}"
                                        data-download-url="{{ $playableUrl ?? '' }}"
                                    >
                                    <label class="form-check-label small text-secondary" for="episode-{{ $episode->id }}">Sélectionner</label>
                                </div>

                                <div class="d-flex flex-column gap-2 align-items-end">
                                    @if ($playableUrl)
                                        <a
                                            href="{{ route('series-infos.episodes.download', ['seriesInfo' => $seriesInfo, 'episode' => $episode]) }}"
                                            class="btn btn-outline-success btn-sm"
                                            download
                                            onclick="event.stopPropagation();"
                                        >
                                            Télécharger
                                        </a>
                                    @endif

                                    <form method="POST" action="{{ route('series-infos.episodes.destroy', ['seriesInfo' => $seriesInfo, 'episode' => $episode]) }}" onsubmit="event.stopPropagation(); return confirm('Supprimer cet épisode ?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Supprimer</button>
                                    </form>
                                </div>
                            </div>

                            <h3 class="h6 card-title mb-2">{{ $episode->title }}</h3>
                            <p class="small mb-0 {{ $playableUrl ? 'text-info' : 'text-secondary' }}">
                                @if ($playableUrl)
                                    Lire ou télécharger maintenant
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

<script>
    const bulkDeleteEpisodesButton = document.getElementById('bulkDeleteEpisodesButton');
    const bulkDownloadEpisodesButton = document.getElementById('bulkDownloadEpisodesButton');
    const downloadSeriesButton = document.getElementById('downloadSeriesButton');
    const bulkDownloadStatus = document.getElementById('bulkDownloadStatus');
    const selectAllEpisodes = document.getElementById('selectAllEpisodes');
    const episodeCheckboxes = Array.from(document.querySelectorAll('.episode-checkbox'));

    const getSelectedEpisodes = () => {
        return episodeCheckboxes.filter((checkbox) => checkbox.checked);
    };

    const getSelectedDownloadableEpisodes = () => {
        return getSelectedEpisodes().filter((checkbox) => checkbox.dataset.downloadUrl !== '');
    };

    const updateBulkDeleteState = () => {
        if (!bulkDeleteEpisodesButton || !bulkDownloadEpisodesButton) {
            return;
        }

        const selectedCount = getSelectedEpisodes().length;
        const downloadableCount = getSelectedDownloadableEpisodes().length;

        bulkDeleteEpisodesButton.disabled = selectedCount === 0;
        bulkDownloadEpisodesButton.disabled = downloadableCount === 0;

        bulkDeleteEpisodesButton.textContent = selectedCount === 0
            ? 'Supprimer la sélection'
            : `Supprimer la sélection (${selectedCount})`;

        bulkDownloadEpisodesButton.textContent = downloadableCount === 0
            ? 'Télécharger la sélection'
            : `Télécharger la sélection (${downloadableCount})`;
    };

    const notifyDownloadStatus = (message, type = 'info') => {
        if (!bulkDownloadStatus) {
            return;
        }

        bulkDownloadStatus.classList.remove('text-secondary', 'text-info', 'text-success', 'text-warning');

        if (type === 'success') {
            bulkDownloadStatus.classList.add('text-success');
        } else if (type === 'warning') {
            bulkDownloadStatus.classList.add('text-warning');
        } else {
            bulkDownloadStatus.classList.add('text-info');
        }

        bulkDownloadStatus.textContent = message;
    };

    const triggerSequentialDownloads = (downloadableEpisodes) => {
        if (downloadableEpisodes.length === 0) {
            notifyDownloadStatus('Aucun épisode sélectionné ne possède un lien final téléchargeable.', 'warning');
            return;
        }

        notifyDownloadStatus(`Préparation de ${downloadableEpisodes.length} téléchargement(s)...`, 'info');

        downloadableEpisodes.forEach((checkbox, index) => {
            window.setTimeout(() => {
                const link = document.createElement('a');
                link.href = checkbox.dataset.downloadUrl;
                link.setAttribute('download', '');
                link.rel = 'noopener';
                link.target = '_blank';
                document.body.appendChild(link);
                link.click();
                link.remove();

                const progress = index + 1;

                if (progress < downloadableEpisodes.length) {
                    notifyDownloadStatus(`Téléchargement ${progress}/${downloadableEpisodes.length} lancé...`, 'info');
                } else {
                    notifyDownloadStatus(`Téléchargement massif terminé: ${downloadableEpisodes.length}/${downloadableEpisodes.length} lancés.`, 'success');
                }
            }, index * 800);
        });
    };

    window.confirmBulkDeleteEpisodes = () => {
        const selectedCount = getSelectedEpisodes().length;

        if (selectedCount === 0) {
            return false;
        }

        return window.confirm(`Supprimer ${selectedCount} épisode(s) sélectionné(s) ?`);
    };

    if (bulkDownloadEpisodesButton) {
        bulkDownloadEpisodesButton.addEventListener('click', () => {
            const downloadableEpisodes = getSelectedDownloadableEpisodes();
            triggerSequentialDownloads(downloadableEpisodes);
        });
    }

    if (downloadSeriesButton) {
        downloadSeriesButton.addEventListener('click', () => {
            const downloadableEpisodes = episodeCheckboxes.filter((checkbox) => checkbox.dataset.downloadUrl !== '');
            triggerSequentialDownloads(downloadableEpisodes);
        });
    }

    if (selectAllEpisodes) {
        selectAllEpisodes.addEventListener('change', (event) => {
            for (const checkbox of episodeCheckboxes) {
                checkbox.checked = event.target.checked;
            }

            updateBulkDeleteState();
        });
    }

    for (const checkbox of episodeCheckboxes) {
        checkbox.addEventListener('change', () => {
            if (selectAllEpisodes) {
                selectAllEpisodes.checked = episodeCheckboxes.length > 0 && episodeCheckboxes.every((item) => item.checked);
            }

            updateBulkDeleteState();
        });
    }

    updateBulkDeleteState();
</script>
</body>
</html>
