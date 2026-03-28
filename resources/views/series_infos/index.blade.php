<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Séries</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<div class="container py-4 py-lg-5 px-3 px-sm-4">
    @if (session('status'))
        <div class="alert alert-success mb-4">{{ session('status') }}</div>
    @endif

    <header class="mb-4 mb-lg-5 d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
        <div>
            <h1 class="display-6 fw-bold mb-2">Séries disponibles</h1>
            <p class="text-secondary mb-0">Cliquez sur une carte pour afficher les épisodes liés.</p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="button" id="openAddSeriesModal" class="btn btn-primary w-auto">Ajouter</button>
            <button type="button" id="refreshAllEpisodesButton" class="btn btn-outline-info w-auto">
                <span id="refreshAllEpisodesSpinner" class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>
                Refresh épisodes
            </button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline-light w-auto">Déconnexion</button>
            </form>
        </div>
    </header>


    <div id="refreshAllEpisodesFeedback" class="alert d-none mb-4"></div>

    <div id="globalScrapeProgress" class="alert alert-info d-none mb-4">
        <div class="d-flex justify-content-between small mb-2">
            <span id="globalScrapeMessage">Récupération des épisodes en cours...</span>
            <span id="globalScrapePercent">0%</span>
        </div>
        <div class="progress" role="progressbar" aria-label="Progression du scraping">
            <div id="globalScrapeBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
        </div>
    </div>

    @if ($seriesInfos->isEmpty())
        <p class="rounded-3 border border-secondary-subtle bg-dark-subtle p-4 text-secondary">Aucune série trouvée.</p>
    @else
        <div class="row g-3 g-md-4 row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-4">
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
                    <article class="card h-100 border-0 shadow-sm bg-dark text-light position-relative">
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
                                <a href="{{ route('series-infos.show', $seriesInfo) }}" class="stretched-link" aria-label="Voir la série {{ $seriesInfo->title ?: 'Sans titre' }}"></a>
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="pe-2 overflow-hidden">
                                        <h2 class="h5 card-title mb-0 text-truncate">{{ $seriesInfo->title ?: 'Sans titre' }}</h2>
                                    </div>
                                    <span class="badge text-bg-primary">{{ $seriesInfo->episodes_count }} épisode(s)</span>
                                </div>

                                <div class="mt-auto d-flex justify-content-end">
                                    <form method="POST" action="{{ route('series-infos.destroy', $seriesInfo) }}" onsubmit="return confirm('Supprimer cette série et tous ses épisodes ?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm position-relative" style="z-index: 2;">Supprimer</button>
                                    </form>
                                </div>

                                {{-- @if ($seriesInfo->story)
                                    <p class="card-text small text-secondary mb-1">{{ \Illuminate\Support\Str::limit($seriesInfo->story, 130) }}</p>
                                @endif --}}

                                {{-- <div class="small text-secondary mt-auto">
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
                                </div> --}}
                            </div>
                    </article>
                </div>
            @endforeach
        </div>
    @endif
</div>

<div id="addSeriesModal" class="position-fixed top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center bg-black bg-opacity-75 p-3" style="z-index: 1050; overflow-y: auto;">
    <div class="card bg-dark text-light border-secondary w-100 my-auto" style="max-width: 600px;">
        <div class="card-body d-flex flex-column gap-3">
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Ajouter une série</h2>
                <button type="button" id="closeAddSeriesModal" class="btn-close btn-close-white" aria-label="Fermer"></button>
            </div>

            {{-- Recherche --}}
            <div>
                <div class="input-group flex-column flex-sm-row gap-2 gap-sm-0">
                    <input
                        type="text"
                        id="searchQuery"
                        class="form-control bg-dark text-light border-secondary w-100"
                        placeholder="Chercher par nom de série..."
                        autocomplete="off"
                        dir="auto"
                    >
                    <button type="button" id="searchBtn" class="btn btn-outline-light w-auto">
                        <span id="searchSpinner" class="spinner-border spinner-border-sm me-1 d-none" role="status" aria-hidden="true"></span>
                        Rechercher
                    </button>
                </div>
                <div id="searchError" class="alert alert-warning py-2 px-3 mt-2 mb-0 d-none small"></div>
            </div>

            {{-- Résultats de recherche --}}
            <div id="searchResults" class="d-none" style="max-height: 300px; overflow-y: auto;">
                <div id="searchResultsList" class="d-flex flex-column gap-2"></div>
            </div>

            <hr class="border-secondary my-0">

            {{-- Formulaire URL directe --}}
            <form id="addSeriesForm" class="d-flex flex-column gap-3">
                <div>
                    <label for="listPageUrl" class="form-label small text-secondary mb-1">Ou collez l'URL directement</label>
                    <input
                        type="url"
                        id="listPageUrl"
                        name="list_page_url"
                        class="form-control bg-dark text-light border-secondary"
                        placeholder="https://n.esheaq.onl/watch/slug/"
                        required
                    >
                </div>

                <div id="addSeriesError" class="alert alert-danger py-2 px-3 mb-0 d-none"></div>

                <div id="episodePreviewSection" class="d-none border border-secondary rounded p-3">
                    <div id="episodePreviewCoverWrapper" class="d-none mb-2">
                        <img
                            id="episodePreviewCover"
                            src=""
                            alt="Couverture de la série"
                            class="rounded border border-secondary"
                            style="width: 72px; height: 100px; object-fit: cover;"
                        >
                    </div>
                    <p id="episodePreviewSummary" class="mb-2 small"></p>
                    <div id="episodeRangeSection" class="row g-2">
                        <div class="col-12 col-sm-6">
                            <label for="episodeStart" class="form-label small text-secondary mb-1">Épisode début</label>
                            <input
                                type="number"
                                id="episodeStart"
                                name="episode_start"
                                class="form-control bg-dark text-light border-secondary"
                                min="1"
                                placeholder="Ex: 21"
                            >
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="episodeEnd" class="form-label small text-secondary mb-1">Épisode fin</label>
                            <input
                                type="number"
                                id="episodeEnd"
                                name="episode_end"
                                class="form-control bg-dark text-light border-secondary"
                                min="1"
                                placeholder="Ex: 40"
                            >
                        </div>
                    </div>
                </div>

                <div id="modalScrapeProgress" class="d-none">
                    <div class="d-flex justify-content-between small mb-2">
                        <span id="modalScrapeMessage">Démarrage...</span>
                        <span id="modalScrapePercent">0%</span>
                    </div>
                    <div class="progress" role="progressbar" aria-label="Progression du scraping">
                        <div id="modalScrapeBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                    </div>
                    <div id="modalScrapeDebug" class="small text-secondary mt-2"></div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitAddSeriesBtn">
                    <span id="addSeriesSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true"></span>
                    Vérifier les épisodes
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    const modalElement = document.getElementById('addSeriesModal');
    const openModalButton = document.getElementById('openAddSeriesModal');
    const closeModalButton = document.getElementById('closeAddSeriesModal');
    const addSeriesForm = document.getElementById('addSeriesForm');
    const submitButton = document.getElementById('submitAddSeriesBtn');
    const spinnerElement = document.getElementById('addSeriesSpinner');
    const errorElement = document.getElementById('addSeriesError');
    const episodePreviewSection = document.getElementById('episodePreviewSection');
    const episodePreviewCoverWrapper = document.getElementById('episodePreviewCoverWrapper');
    const episodePreviewCover = document.getElementById('episodePreviewCover');
    const episodePreviewSummary = document.getElementById('episodePreviewSummary');
    const episodeRangeSection = document.getElementById('episodeRangeSection');
    const episodeStartInput = document.getElementById('episodeStart');
    const episodeEndInput = document.getElementById('episodeEnd');

    // Recherche externe
    const searchQueryInput = document.getElementById('searchQuery');
    const searchBtn = document.getElementById('searchBtn');
    const searchSpinner = document.getElementById('searchSpinner');
    const searchError = document.getElementById('searchError');
    const searchResults = document.getElementById('searchResults');
    const searchResultsList = document.getElementById('searchResultsList');
    const listPageUrlInput = document.getElementById('listPageUrl');

    const refreshAllEpisodesButton = document.getElementById('refreshAllEpisodesButton');
    const refreshAllEpisodesSpinner = document.getElementById('refreshAllEpisodesSpinner');
    const refreshAllEpisodesFeedback = document.getElementById('refreshAllEpisodesFeedback');


    const setRefreshFeedback = (message, type) => {
        refreshAllEpisodesFeedback.className = `alert alert-${type} mb-4`;
        refreshAllEpisodesFeedback.textContent = message;
        refreshAllEpisodesFeedback.classList.remove('d-none');
    };

    refreshAllEpisodesButton.addEventListener('click', async () => {
        refreshAllEpisodesButton.disabled = true;
        refreshAllEpisodesSpinner.classList.remove('d-none');
        refreshAllEpisodesFeedback.classList.add('d-none');

        try {
            const response = await fetch('{{ route('series-infos.refresh-all') }}', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });

            const data = await response.json();

            if (!response.ok || data.status === 'error') {
                throw new Error(data.message ?? 'Erreur pendant la synchronisation.');
            }

            if (data.status === 'busy') {
                setRefreshFeedback(data.message, 'warning');
                return;
            }

            if (Number(data.new_episodes_count ?? 0) > 0) {
                setRefreshFeedback(`✅ ${data.new_episodes_count} nouveau(x) épisode(s) importé(s).`, 'success');
            } else {
                setRefreshFeedback('Aucun nouvel épisode trouvé.', 'info');
            }

            window.setTimeout(() => {
                window.location.reload();
            }, 1200);
        } catch (error) {
            setRefreshFeedback(error.message, 'danger');
        } finally {
            refreshAllEpisodesButton.disabled = false;
            refreshAllEpisodesSpinner.classList.add('d-none');
        }
    });

    const runSearch = async () => {
        const q = searchQueryInput.value.trim();
        if (q === '') return;

        searchBtn.disabled = true;
        searchSpinner.classList.remove('d-none');
        searchError.classList.add('d-none');
        searchResults.classList.add('d-none');
        searchResultsList.innerHTML = '';

        try {
            const url = '{{ route('series-infos.search-external') }}?q=' + encodeURIComponent(q);
            const resp = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await resp.json();

            if (!resp.ok) {
                searchError.textContent = data.error ?? 'Erreur lors de la recherche.';
                searchError.classList.remove('d-none');
                return;
            }

            if (!data.results || data.results.length === 0) {
                searchError.textContent = 'Aucun résultat trouvé.';
                searchError.classList.remove('d-none');
                return;
            }

            data.results.forEach(item => {
                const card = document.createElement('div');
                card.className = 'd-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-3 p-2 rounded border border-secondary bg-black bg-opacity-25';

                const img = item.image
                    ? `<img src="${item.image}" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:4px;flex-shrink:0;" loading="lazy">`
                    : `<div style="width:56px;height:56px;background:#333;border-radius:4px;flex-shrink:0;"></div>`;

                card.innerHTML = `
                    ${img}
                    <div class="flex-grow-1 overflow-hidden w-100">
                        <div class="fw-semibold text-truncate" dir="auto">${item.title}</div>
                        <div class="small text-secondary text-truncate">${item.url}</div>
                    </div>
                    <button type="button" class="btn btn-sm btn-success flex-shrink-0 w-auto">Ajouter</button>
                `;

                card.querySelector('button').addEventListener('click', () => {
                    listPageUrlInput.value = item.url;
                    addSeriesForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                });

                searchResultsList.appendChild(card);
            });

            searchResults.classList.remove('d-none');
        } catch (e) {
            searchError.textContent = 'Erreur réseau : ' + e.message;
            searchError.classList.remove('d-none');
        } finally {
            searchBtn.disabled = false;
            searchSpinner.classList.add('d-none');
        }
    };

    searchBtn.addEventListener('click', runSearch);
    searchQueryInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
    });
    listPageUrlInput.addEventListener('input', () => {
        if (!isPreviewStepCompleted) {
            return;
        }

        isPreviewStepCompleted = false;
        episodePreviewSection.classList.add('d-none');
        episodePreviewCoverWrapper.classList.add('d-none');
        episodePreviewCover.src = '';
        episodePreviewSummary.textContent = '';
        episodeRangeSection.classList.remove('d-none');
        episodeStartInput.value = '';
        episodeEndInput.value = '';
        submitButton.textContent = 'Vérifier les épisodes';
        submitButton.prepend(spinnerElement);
    });
    const modalScrapeProgress = document.getElementById('modalScrapeProgress');
    const modalScrapeMessage = document.getElementById('modalScrapeMessage');
    const modalScrapePercent = document.getElementById('modalScrapePercent');
    const modalScrapeBar = document.getElementById('modalScrapeBar');
    const modalScrapeDebug = document.getElementById('modalScrapeDebug');
    const globalScrapeProgress = document.getElementById('globalScrapeProgress');
    const globalScrapeMessage = document.getElementById('globalScrapeMessage');
    const globalScrapePercent = document.getElementById('globalScrapePercent');
    const globalScrapeBar = document.getElementById('globalScrapeBar');

    let pollingIntervalId = null;
    let hasReloadedAfterSeriesCreation = false;
    let lastProgressTimestamp = null;
    let isPreviewStepCompleted = false;

    const toggleModal = (isOpen) => {
        if (isOpen) {
            modalElement.classList.remove('d-none');
            modalElement.classList.add('d-flex');
            return;
        }

        modalElement.classList.remove('d-flex');
        modalElement.classList.add('d-none');
    };

    const updateProgressUi = (data) => {
        const percent = Number(data.progressPercent ?? 0);
        const message = data.message ?? 'Récupération en cours...';
        const episodesProcessed = Number(data.episodesProcessed ?? 0);
        const episodesTotal = Number(data.episodesTotal ?? 0);
        const currentEpisodeTitle = data.currentEpisodeTitle ?? 'N/A';
        const updatedAt = data.updatedAt ? new Date(data.updatedAt) : null;

        if (updatedAt instanceof Date && !Number.isNaN(updatedAt.valueOf())) {
            lastProgressTimestamp = updatedAt;
        }

        modalScrapeProgress.classList.remove('d-none');
        modalScrapeMessage.textContent = message;
        modalScrapePercent.textContent = `${percent}%`;
        modalScrapeBar.style.width = `${percent}%`;

        const debugParts = [
            `État: ${data.state ?? 'running'}`,
            `Épisodes: ${episodesProcessed}/${episodesTotal}`,
            `Épisode courant: ${currentEpisodeTitle}`,
            `Dernière mise à jour: ${updatedAt ? updatedAt.toLocaleTimeString() : 'N/A'}`,
        ];

        if (data.lastError) {
            debugParts.push(`Dernière erreur: ${data.lastError}`);
        }

        modalScrapeDebug.textContent = debugParts.join(' | ');

        globalScrapeProgress.classList.remove('d-none');
        globalScrapeMessage.textContent = message;
        globalScrapePercent.textContent = `${percent}%`;
        globalScrapeBar.style.width = `${percent}%`;
    };

    const stopPolling = () => {
        if (pollingIntervalId !== null) {
            clearInterval(pollingIntervalId);
            pollingIntervalId = null;
        }
    };

    const startPolling = (trackingKey) => {
        const poll = async () => {
            const response = await fetch(`{{ route('series-infos.scrape-status', ['trackingKey' => '__TRACKING_KEY__']) }}`.replace('__TRACKING_KEY__', trackingKey), {
                headers: {
                    'Accept': 'application/json',
                },
            });

            if (response.status === 404) {
                return;
            }

            if (!response.ok) {
                throw new Error('Impossible de récupérer la progression.');
            }

            const data = await response.json();
            updateProgressUi(data);

            if (lastProgressTimestamp !== null && Date.now() - lastProgressTimestamp.getTime() > 120000 && data.state === 'running') {
                throw new Error('Le scraping semble bloqué (aucune mise à jour depuis plus de 2 minutes). Vérifiez que le worker de queue tourne: php artisan queue:work.');
            }

            if (data.seriesInfoId && !hasReloadedAfterSeriesCreation) {
                hasReloadedAfterSeriesCreation = true;
                stopPolling();
                toggleModal(false);
                window.location.reload();
                return;
            }

            if (data.state === 'completed') {
                stopPolling();
                window.location.reload();
            }

            if (data.state === 'error') {
                stopPolling();
                throw new Error(data.message ?? 'Erreur de scraping.');
            }
        };

        poll().catch((error) => {
            errorElement.textContent = error.message;
            errorElement.classList.remove('d-none');
            submitButton.disabled = false;
            spinnerElement.classList.add('d-none');
        });

        pollingIntervalId = window.setInterval(() => {
            poll().catch((error) => {
                stopPolling();
                errorElement.textContent = error.message;
                errorElement.classList.remove('d-none');
                submitButton.disabled = false;
                spinnerElement.classList.add('d-none');
            });
        }, 2500);
    };

    openModalButton.addEventListener('click', () => {
        errorElement.classList.add('d-none');
        errorElement.textContent = '';
        modalScrapeProgress.classList.add('d-none');
        modalScrapeDebug.textContent = '';
        lastProgressTimestamp = null;
        addSeriesForm.reset();
        searchQueryInput.value = '';
        searchError.classList.add('d-none');
        searchResults.classList.add('d-none');
        searchResultsList.innerHTML = '';
        isPreviewStepCompleted = false;
        episodePreviewSection.classList.add('d-none');
        episodePreviewCoverWrapper.classList.add('d-none');
        episodePreviewCover.src = '';
        episodePreviewSummary.textContent = '';
        episodeRangeSection.classList.remove('d-none');
        submitButton.textContent = 'Vérifier les épisodes';
        submitButton.prepend(spinnerElement);
        toggleModal(true);
    });

    closeModalButton.addEventListener('click', () => {
        toggleModal(false);
    });

    addSeriesForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        spinnerElement.classList.remove('d-none');
        submitButton.disabled = true;
        errorElement.classList.add('d-none');

        const formData = new FormData(addSeriesForm);

        // Debug: log les valeurs envoyées
        console.log('[Scrape] isPreviewStepCompleted:', isPreviewStepCompleted);
        console.log('[Scrape] episode_start:', formData.get('episode_start'));
        console.log('[Scrape] episode_end:', formData.get('episode_end'));
        console.log('[Scrape] list_page_url:', formData.get('list_page_url'));

        try {
            const endpoint = isPreviewStepCompleted
                ? '{{ route('series-infos.scrape') }}'
                : '{{ route('series-infos.scrape-preview') }}';

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: formData,
            });

            const responseData = await response.json();

            if (!response.ok) {
                const firstError = responseData?.errors?.list_page_url?.[0] ?? responseData?.message ?? 'Une erreur est survenue.';
                throw new Error(firstError);
            }

            if (!isPreviewStepCompleted) {
                const episodesTotal = Number(responseData.episodesTotal ?? 0);
                const hasEpisodeNumbers = Boolean(responseData.hasEpisodeNumbers);
                const episodeMin = responseData.episodeMin;
                const episodeMax = responseData.episodeMax;
                const coverImageUrl = responseData.coverImageUrl;

                if (episodesTotal === 0) {
                    throw new Error('Aucun épisode trouvé sur cette URL.');
                }

                episodePreviewSection.classList.remove('d-none');
                if (typeof coverImageUrl === 'string' && coverImageUrl !== '') {
                    episodePreviewCover.src = coverImageUrl;
                    episodePreviewCoverWrapper.classList.remove('d-none');
                } else {
                    episodePreviewCover.src = '';
                    episodePreviewCoverWrapper.classList.add('d-none');
                }

                if (hasEpisodeNumbers) {
                    episodePreviewSummary.textContent = `Épisodes trouvés: ${episodesTotal}. Plage détectée: ${episodeMin} à ${episodeMax}.`;
                    episodeRangeSection.classList.remove('d-none');
                    episodeStartInput.min = String(episodeMin);
                    episodeStartInput.max = String(episodeMax);
                    episodeEndInput.min = String(episodeMin);
                    episodeEndInput.max = String(episodeMax);

                    if (episodeStartInput.value === '') {
                        episodeStartInput.value = String(episodeMin);
                    }

                    if (episodeEndInput.value === '') {
                        episodeEndInput.value = String(episodeMax);
                    }
                } else {
                    episodePreviewSummary.textContent = `Épisodes trouvés: ${episodesTotal}. Impossible de détecter les numéros, le téléchargement portera sur tous les épisodes détectés.`;
                    episodeRangeSection.classList.add('d-none');
                    episodeStartInput.value = '';
                    episodeEndInput.value = '';
                }

                isPreviewStepCompleted = true;
                submitButton.textContent = 'Lancer téléchargement';
                submitButton.prepend(spinnerElement);
                spinnerElement.classList.add('d-none');
                submitButton.disabled = false;
                return;
            }

            hasReloadedAfterSeriesCreation = false;
            lastProgressTimestamp = null;
            stopPolling();
            spinnerElement.classList.add('d-none');
            startPolling(responseData.trackingKey);
        } catch (error) {
            errorElement.textContent = error.message;
            errorElement.classList.remove('d-none');
            spinnerElement.classList.add('d-none');
            submitButton.disabled = false;
        }
    });
</script>
</body>
</html>
