<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $seriesInfo->title ?: 'Série' }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css">
    <style>
        #videoPlayerModal .modal-dialog {
            max-width: min(96vw, 1400px);
            margin: 1rem auto;
        }

        #videoPlayerModal .modal-content {
            height: min(95vh, 920px);
        }

        #videoPlayerModal .modal-body {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        #videoPlayerModal .video-player-frame {
            flex: 1 1 auto;
            min-height: 260px;
            max-height: calc(95vh - 180px);
            border-radius: 0.5rem;
            overflow: hidden;
            background-color: #000;
        }

        #videoPlayerModal .video-player-frame video,
        #videoPlayerModal .video-player-frame .plyr {
            width: 100%;
            height: 100%;
        }

        @media (max-height: 760px) {
            #videoPlayerModal .modal-content {
                height: 100vh;
            }

            #videoPlayerModal .video-player-frame {
                max-height: calc(100vh - 160px);
            }
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="container py-4 py-lg-5">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <a href="{{ route('series-infos.index') }}" class="text-decoration-none text-info">← Retour aux séries</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-outline-light btn-sm">Déconnexion</button>
        </form>
    </div>

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
        $episodesPendingTitles = $seriesInfo->episodes
            ->whereIn('status', [\App\Models\Episode::STATUS_PENDING, \App\Models\Episode::STATUS_IN_PROGRESS])
            ->pluck('title')
            ->take(12)
            ->values();
        $isScrapingInProgress = ($episodesInProgress + $episodesPending) > 0;
        $progressPercent = $episodesTotal > 0 ? (int) round(($episodesDone / $episodesTotal) * 100) : 0;
    @endphp

    <section class="mt-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h4 mb-0">Épisodes</h2>

            <button
                type="button"
                id="refreshEpisodesButton"
                data-retry-url="{{ route('series-infos.retry-errors', $seriesInfo) }}"
                class="btn btn-outline-warning btn-sm"
            >
                Retry erreurs
            </button>
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

                <details class="mt-3" id="scrapeLogsContainer">
                    <summary class="small fw-semibold">Voir le détail technique en temps réel</summary>

                    <div class="mt-2 small">
                        <ul class="mb-0 ps-3" id="scrapeLogsList">
                            <li>[Init] Préparation de la récupération des épisodes...</li>
                            @foreach ($episodesPendingTitles as $pendingTitle)
                                <li>[File d'attente] Téléchargement en cours: {{ $pendingTitle }}</li>
                            @endforeach
                        </ul>
                    </div>
                </details>
            </div>
        @endif

        <div class="row g-4 row-cols-2 row-cols-lg-3 row-cols-xxl-4">
            @forelse ($seriesInfo->episodes as $episode)
                @php
                    $playableServer = $episode->servers->first(fn ($server): bool => (string) $server->final_url !== '');
                    $playableUrl = $playableServer?->final_url;
                @endphp

                <div class="col">
                    <article class="card h-100 border-0 shadow-sm bg-dark text-light">
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
                                        data-download-url="{{ $playableUrl ? route('series-infos.episodes.download', ['seriesInfo' => $seriesInfo, 'episode' => $episode]) : '' }}"
                                    >
                                    <label class="form-check-label small text-secondary" for="episode-{{ $episode->id }}">Sélectionner</label>
                                </div>

                                <div class="d-flex flex-column gap-2 align-items-end">
                                    @if ($playableUrl)
                                        <button
                                            type="button"
                                            class="btn btn-outline-info btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#videoPlayerModal"
                                            data-video-url="{{ $playableUrl }}"
                                            data-video-key="episode-{{ $episode->id }}"
                                            data-video-title="{{ $episode->title }}"
                                        >
                                            Lire
                                        </button>

                                        <a
                                            href="{{ route('series-infos.episodes.download', ['seriesInfo' => $seriesInfo, 'episode' => $episode]) }}"
                                            class="btn btn-outline-success btn-sm"
                                        >
                                            Télécharger
                                        </a>
                                    @endif

                                    <form method="POST" action="{{ route('series-infos.episodes.destroy', ['seriesInfo' => $seriesInfo, 'episode' => $episode]) }}" onsubmit="return confirm('Supprimer cet épisode ?');">
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
                </div>
            @empty
                <p class="rounded-3 border border-secondary-subtle bg-dark-subtle p-4 text-secondary">Aucun épisode lié à cette série.</p>
            @endforelse
        </div>
    </section>
</div>

<div class="modal fade" id="videoPlayerModal" tabindex="-1" aria-labelledby="videoPlayerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down">
        <div class="modal-content bg-dark text-light border border-secondary-subtle">
            <div class="modal-header">
                <h5 class="modal-title" id="videoPlayerModalLabel">Lecture vidéo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body p-2 p-md-3 gap-2">
                <div class="video-player-frame">
                    <video id="episodeVideoPlayer" playsinline controls class="w-100 h-100"></video>
                </div>
                <p class="small text-secondary mb-0" id="videoPlayerStatus"></p>
            </div>
        </div>
    </div>
</div>

@if ($isScrapingInProgress)
    <script>
        window.setTimeout(() => {
            window.location.reload();
        }, 10000);
    </script>
@endif

<script>
        const refreshEpisodesButton = document.getElementById('refreshEpisodesButton');
        const refreshEpisodesError = document.getElementById('refreshEpisodesError');
        const scrapeLogsContainer = document.getElementById('scrapeLogsContainer');
        const scrapeLogsList = document.getElementById('scrapeLogsList');
        const knownLogMessages = new Set();

        const levelPrefix = {
            info: '[Info]',
            success: '[OK]',
            error: '[Erreur]',
        };

        const formatLogMessage = (message, level = 'info', time = null) => {
            const safeMessage = String(message ?? '').trim();
            if (!safeMessage) {
                return '';
            }

            const prefix = levelPrefix[level] ?? '[Info]';
            const timePrefix = time ? `[${time}] ` : '';

            return `${timePrefix}${prefix} ${safeMessage}`;
        };

        const addScrapeLog = (message, level = 'info', time = null) => {
            if (!scrapeLogsList) {
                return;
            }

            const normalizedMessage = formatLogMessage(message, level, time);
            if (!normalizedMessage || knownLogMessages.has(normalizedMessage)) {
                return;
            }

            const item = document.createElement('li');
            item.textContent = normalizedMessage;
            scrapeLogsList.appendChild(item);
            knownLogMessages.add(normalizedMessage);

            while (scrapeLogsList.children.length > 40) {
                const firstItem = scrapeLogsList.firstElementChild;
                if (!firstItem) {
                    break;
                }

                knownLogMessages.delete(firstItem.textContent ?? '');
                firstItem.remove();
            }

            if (scrapeLogsContainer && scrapeLogsContainer.open) {
                scrapeLogsContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        };

        const syncEventsFromStatus = (events) => {
            if (!Array.isArray(events)) {
                return;
            }

            events.forEach((event) => {
                addScrapeLog(event?.message ?? '', event?.level ?? 'info', event?.time ?? null);
            });
        };

        if (scrapeLogsList) {
            Array.from(scrapeLogsList.querySelectorAll('li')).forEach((item) => {
                knownLogMessages.add(item.textContent.trim());
            });
        }

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

                syncEventsFromStatus(data.events);

                const message = data.message ?? 'Récupération en cours...';
                addScrapeLog(message);

                if (data.currentEpisodeTitle) {
                    addScrapeLog(`Téléchargement épisode: ${data.currentEpisodeTitle}`);
                }

                if (Number.isInteger(data.episodesProcessed) && Number.isInteger(data.episodesTotal) && data.episodesTotal > 0) {
                    addScrapeLog(`Progression: ${data.episodesProcessed}/${data.episodesTotal}`);
                }

                if (data.state === 'error') {
                    throw new Error(data.lastError ?? data.message ?? 'Erreur pendant le refresh.');
                }

                if (data.state === 'completed') {
                    addScrapeLog('Scraping terminé. Rechargement de la page...');
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
            addScrapeLog('Retry des épisodes en erreur...');

            try {
                const response = await fetch(refreshEpisodesButton.dataset.retryUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data?.message ?? 'Impossible de lancer le retry.');
                }

                startPolling(data.trackingKey);
            } catch (error) {
                setRefreshButtonState(false);
                refreshEpisodesError.textContent = error.message;
                refreshEpisodesError.classList.remove('d-none');
            }
        });
    </script>

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
                const iframe = document.createElement('iframe');
                iframe.src = checkbox.dataset.downloadUrl;
                iframe.classList.add('d-none');
                document.body.appendChild(iframe);

                window.setTimeout(() => {
                    iframe.remove();
                }, 15000);

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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
<script>
    const videoPlayerModalElement = document.getElementById('videoPlayerModal');
    const videoElement = document.getElementById('episodeVideoPlayer');
    const videoPlayerStatus = document.getElementById('videoPlayerStatus');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const isAuthenticated = {{ auth()->check() ? 'true' : 'false' }};
    const historyShowUrl = "{{ route('video-watch-histories.show') }}";
    const historyUpsertUrl = "{{ route('video-watch-histories.upsert') }}";
    let player = null;
    let activeVideo = null;
    let saveIntervalId = null;
    let isSaving = false;

    const updateVideoStatus = (message) => {
        if (!videoPlayerStatus) {
            return;
        }

        videoPlayerStatus.textContent = message;
    };

    const fallbackStorageKey = (videoKey) => {
        return `video-progress:${videoKey}`;
    };

    const saveLocalProgress = (payload) => {
        window.localStorage.setItem(fallbackStorageKey(payload.video_key), JSON.stringify(payload));
    };

    const getLocalProgress = (videoKey) => {
        const rawValue = window.localStorage.getItem(fallbackStorageKey(videoKey));
        if (rawValue === null) {
            return null;
        }

        try {
            return JSON.parse(rawValue);
        } catch (_error) {
            return null;
        }
    };

    const getProgressPayload = (markAsCompleted = false) => {
        if (!player || !activeVideo) {
            return null;
        }

        const duration = Number.isFinite(player.duration) && player.duration > 0 ? Math.round(player.duration) : 0;
        const currentTime = Number.isFinite(player.currentTime) && player.currentTime > 0 ? Math.round(player.currentTime) : 0;
        const completed = markAsCompleted || (duration > 0 && currentTime >= Math.max(duration - 2, 0));

        return {
            video_key: activeVideo.videoKey,
            video_url: activeVideo.videoUrl,
            current_time: completed ? duration : Math.min(currentTime, duration || currentTime),
            duration,
            completed,
            last_watched_at: (new Date()).toISOString(),
        };
    };

    const saveProgress = async (markAsCompleted = false, silent = false) => {
        if (isSaving) {
            return;
        }

        const payload = getProgressPayload(markAsCompleted);
        if (!payload) {
            return;
        }

        if (!isAuthenticated) {
            saveLocalProgress(payload);
            if (!silent) {
                updateVideoStatus(payload.completed ? 'Lecture terminée (mode invité).' : 'Progression sauvegardée localement.');
            }
            return;
        }

        isSaving = true;

        try {
            const response = await fetch(historyUpsertUrl, {
                method: 'PUT',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok && !silent) {
                updateVideoStatus('Impossible de sauvegarder votre progression pour le moment.');
            } else if (!silent) {
                updateVideoStatus(payload.completed ? 'Lecture terminée.' : 'Progression sauvegardée.');
            }
        } finally {
            isSaving = false;
        }
    };

    const loadProgress = async (videoKey, videoUrl) => {
        if (!isAuthenticated) {
            const localHistory = getLocalProgress(videoKey);
            if (localHistory?.video_url === videoUrl) {
                return localHistory;
            }

            return null;
        }

        const params = new URLSearchParams({
            video_key: videoKey,
            video_url: videoUrl,
        });
        const response = await fetch(`${historyShowUrl}?${params.toString()}`, {
            headers: {
                'Accept': 'application/json',
            },
        });

        if (!response.ok) {
            return null;
        }

        const data = await response.json();
        return data.history ?? null;
    };

    const waitForVideoMetadata = (mediaElement) => {
        return new Promise((resolve) => {
            if (!mediaElement) {
                resolve();
                return;
            }

            if (mediaElement.readyState >= 1) {
                resolve();
                return;
            }

            mediaElement.addEventListener('loadedmetadata', () => {
                resolve();
            }, { once: true });
        });
    };

    if (videoPlayerModalElement && videoElement) {
        videoPlayerModalElement.addEventListener('show.bs.modal', async (event) => {
            const trigger = event.relatedTarget;
            if (!(trigger instanceof HTMLElement)) {
                return;
            }

            const videoUrl = trigger.dataset.videoUrl ?? '';
            const videoKey = trigger.dataset.videoKey ?? '';
            const videoTitle = trigger.dataset.videoTitle ?? 'Lecture vidéo';
            if (!videoUrl || !videoKey) {
                return;
            }

            activeVideo = {
                videoUrl,
                videoKey,
            };

            const modalTitle = document.getElementById('videoPlayerModalLabel');
            if (modalTitle) {
                modalTitle.textContent = videoTitle;
            }

            if (player === null) {
                player = new Plyr(videoElement, {
                    controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
                });
            }

            player.source = {
                type: 'video',
                sources: [
                    {
                        src: videoUrl,
                        type: 'video/mp4',
                    },
                ],
            };
            await waitForVideoMetadata(player.media);

            if (saveIntervalId !== null) {
                window.clearInterval(saveIntervalId);
                saveIntervalId = null;
            }

            try {
                const history = await loadProgress(videoKey, videoUrl);
                const canResume = history && !history.completed && Number(history.current_time) > 0;
                if (canResume) {
                    const resumeTime = Math.max(Number(history.current_time), 0);
                    player.currentTime = resumeTime;
                    updateVideoStatus(`Reprise à ${resumeTime}s.`);
                } else {
                    updateVideoStatus('Lecture démarrée.');
                }
            } catch (_error) {
                updateVideoStatus('Lecture démarrée.');
            }

            saveIntervalId = window.setInterval(() => {
                saveProgress(false, true);
            }, 10000);

            try {
                await player.play();
            } catch (_error) {
                updateVideoStatus('Cliquez sur lecture pour démarrer la vidéo.');
            }
        });

        videoPlayerModalElement.addEventListener('hidden.bs.modal', async () => {
            if (saveIntervalId !== null) {
                window.clearInterval(saveIntervalId);
                saveIntervalId = null;
            }

            await saveProgress(false, true);

            if (player) {
                player.pause();
                player.currentTime = 0;
            }

            videoElement.removeAttribute('src');
            videoElement.innerHTML = '';
            videoElement.load();
            activeVideo = null;
            updateVideoStatus('');
        });

        videoElement.addEventListener('ended', () => {
            saveProgress(true);
        });
    }
</script>
</body>
</html>
