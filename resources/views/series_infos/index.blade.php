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
<div class="container py-4 py-lg-5">
    <header class="mb-4 mb-lg-5 d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3">
        <div>
            <h1 class="display-6 fw-bold mb-2">Séries disponibles</h1>
            <p class="text-secondary mb-0">Cliquez sur une carte pour afficher les épisodes liés.</p>
        </div>

        <button type="button" id="openAddSeriesModal" class="btn btn-primary">Ajouter</button>
    </header>

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
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>

<div id="addSeriesModal" class="position-fixed top-0 start-0 w-100 h-100 d-none align-items-center justify-content-center bg-black bg-opacity-75 p-3" style="z-index: 1050;">
    <div class="card bg-dark text-light border-secondary w-100" style="max-width: 560px;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Ajouter une série</h2>
                <button type="button" id="closeAddSeriesModal" class="btn-close btn-close-white" aria-label="Fermer"></button>
            </div>

            <form id="addSeriesForm" class="d-flex flex-column gap-3">
                <div>
                    <label for="listPageUrl" class="form-label">URL de la page liste</label>
                    <input
                        type="url"
                        id="listPageUrl"
                        name="list_page_url"
                        class="form-control"
                        placeholder="https://example.com/series"
                        required
                    >
                </div>

                <div id="addSeriesError" class="alert alert-danger py-2 px-3 mb-0 d-none"></div>

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
                    Lancer
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
        toggleModal(true);
    });

    closeModalButton.addEventListener('click', () => {
        toggleModal(false);
    });

    addSeriesForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        hasReloadedAfterSeriesCreation = false;
        lastProgressTimestamp = null;
        stopPolling();
        spinnerElement.classList.remove('d-none');
        submitButton.disabled = true;
        errorElement.classList.add('d-none');

        const formData = new FormData(addSeriesForm);

        try {
            const response = await fetch('{{ route('series-infos.scrape') }}', {
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
