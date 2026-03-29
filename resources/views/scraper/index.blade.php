<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>جميع المسلسلات - Scraper</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            min-height: 100vh;
            direction: rtl;
            font-family: 'Segoe UI', 'Helvetica Neue', sans-serif;
            color: #fff;
        }

        /* Navbar Netflix Style */
        .navbar-custom {
            background: linear-gradient(180deg, rgba(0,0,0,0.9) 0%, rgba(0,0,0,0.7) 100%);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
        }

        .navbar-brand {
            font-size: 1.8em !important;
            font-weight: 900 !important;
            background: linear-gradient(90deg, #e50914 0%, #ff6b6b 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 2px;
        }

        .nav-link {
            color: rgba(255,255,255,0.7) !important;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-left: 15px;
        }

        .nav-link:hover,
        .nav-link.active {
            color: #fff !important;
            text-shadow: 0 0 10px rgba(229, 9, 20, 0.5);
        }

        /* Header */
        header {
            text-align: center;
            margin: 50px 0 40px;
            padding: 0 20px;
        }

        header h1 {
            font-size: clamp(2em, 5vw, 3.5em);
            font-weight: 900;
            text-shadow: 0 4px 20px rgba(229, 9, 20, 0.5);
            letter-spacing: 1px;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #fff 0%, #e8e8e8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        header p {
            font-size: 1.1em;
            color: rgba(255,255,255,0.6);
            letter-spacing: 0.5px;
        }

        /* Alerts */
        #errorAlert, #successAlert {
            border: none;
            border-radius: 8px;
            backdrop-filter: blur(10px);
            margin: 20px auto;
            max-width: 600px;
        }

        #errorAlert {
            background: rgba(220, 20, 60, 0.2);
            color: #ff6b6b;
            border-left: 4px solid #dc143c;
        }

        #successAlert {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border-left: 4px solid #22c55e;
        }

        .alert-dismissible .btn-close {
            filter: brightness(0.8);
        }

        /* Controls */
        .controls {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
            padding: 0 20px;
        }

        .btn-action {
            padding: 12px 28px;
            border-radius: 6px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1em;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .btn-action:not(.btn-clear) {
            background: linear-gradient(90deg, #e50914 0%, #ff6b6b 100%);
            color: white;
            box-shadow: 0 8px 24px rgba(229, 9, 20, 0.35);
        }

        .btn-action:not(.btn-clear):hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(229, 9, 20, 0.5);
            background: linear-gradient(90deg, #ff4757 0%, #ff6b6b 100%);
        }

        .btn-action:not(.btn-clear):active:not(:disabled) {
            transform: translateY(-1px);
        }

        .btn-clear {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .btn-clear:hover:not(:disabled) {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 0 8px 24px rgba(255, 255, 255, 0.2);
        }

        .btn-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Cache Info */
        .cache-info {
            text-align: center;
            color: rgba(255,255,255,0.6);
            font-size: 0.95em;
            margin-top: 15px;
            letter-spacing: 0.5px;
        }

        /* Stats */
        .stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin: 40px 0;
            color: white;
            flex-wrap: wrap;
            padding: 30px 20px;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .stat {
            text-align: center;
        }

        .stat-number {
            font-size: 3em;
            font-weight: 900;
            color: #e50914;
            text-shadow: 0 0 20px rgba(229, 9, 20, 0.5);
        }

        .stat-label {
            font-size: 1em;
            color: rgba(255,255,255,0.7);
            margin-top: 8px;
            letter-spacing: 1px;
        }

        /* Loading */
        .loading-container {
            display: none;
            text-align: center;
            margin: 40px 0;
        }

        .loading-container.active {
            display: block;
        }

        .spinner {
            border: 4px solid rgba(229, 9, 20, 0.2);
            border-top: 4px solid #e50914;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .progress-container {
            max-width: 500px;
            margin: 0 auto;
        }

        .progress {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(90deg, #e50914 0%, #ff6b6b 100%);
            height: 100%;
        }

        .progress-text {
            color: rgba(255,255,255,0.7);
            font-size: 0.9em;
            margin-top: 12px;
            letter-spacing: 0.5px;
        }

        /* Netflix Grid */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 15px;
            margin: 40px 20px;
            padding: 0;
        }

        /* Netflix Card */
        .serie-card {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            transform: scale(1);
            z-index: 1;
            opacity: 0;
            animation: cardAppear 0.6s ease-out forwards;
        }

        @keyframes cardAppear {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .serie-card:hover {
            transform: scale(1.08);
            z-index: 10;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.8);
        }

        .serie-image-wrapper {
            position: relative;
            width: 100%;
            aspect-ratio: 2 / 3;
            overflow: hidden;
            background: linear-gradient(135deg, #2a2a2a 0%, #1a1a1a 100%);
        }

        .serie-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: all 0.4s ease;
        }

        .serie-card:hover .serie-image {
            transform: scale(1.05) brightness(0.7);
        }

        /* Overlay on Hover */
        .serie-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            top: 0;
            background: linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.8) 70%, rgba(0,0,0,0.95) 100%);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 20px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .serie-card:hover .serie-overlay {
            opacity: 1;
        }

        .serie-title-overlay {
            color: white;
            font-size: 1.1em;
            font-weight: 700;
            margin-bottom: 12px;
            line-height: 1.3;
            text-shadow: 0 2px 4px rgba(0,0,0,0.5);
        }

        .serie-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-start;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease 0.1s;
        }

        .serie-card:hover .serie-actions {
            opacity: 1;
            transform: translateY(0);
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 2px solid white;
            background: transparent;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8em;
        }

        .action-btn:hover {
            background: white;
            color: #1a1a1a;
            transform: scale(1.1);
        }

        /* Title Below Card */
        .serie-title {
            padding: 12px;
            text-align: center;
            font-size: 0.9em;
            color: rgba(255,255,255,0.9);
            line-height: 1.4;
            font-weight: 600;
            background: rgba(0,0,0,0.3);
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            color: rgba(255,255,255,0.8);
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 5em;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h2 {
            font-size: 2em;
            margin-bottom: 10px;
            color: rgba(255,255,255,0.7);
        }

        .empty-state p {
            font-size: 1.1em;
            color: rgba(255,255,255,0.5);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 12px;
            }
        }

        @media (max-width: 768px) {
            header h1 {
                font-size: 2.2em;
            }

            .grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 10px;
                margin: 30px 10px;
            }

            .controls {
                gap: 10px;
                margin-bottom: 30px;
            }

            .btn-action {
                padding: 10px 16px;
                font-size: 0.85em;
            }

            .stats {
                gap: 20px;
                padding: 20px 10px;
            }

            .stat-number {
                font-size: 2.2em;
            }
        }

        @media (max-width: 480px) {
            header {
                margin: 30px 0 30px;
            }

            header h1 {
                font-size: 1.8em;
            }

            header p {
                font-size: 0.9em;
            }

            .grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
                gap: 8px;
                margin: 20px 5px;
            }

            .controls {
                gap: 8px;
                flex-direction: column;
                margin-bottom: 20px;
            }

            .btn-action {
                width: 100%;
                padding: 10px 12px;
                font-size: 0.75em;
            }

            .stats {
                flex-direction: column;
                gap: 15px;
                padding: 15px 10px;
            }

            .stat-number {
                font-size: 2em;
            }

            .serie-title {
                font-size: 0.75em;
                padding: 8px;
                min-height: 40px;
            }
        }

        /* Smooth scroll behavior */
        html {
            scroll-behavior: smooth;
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 12px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.5);
        }

        ::-webkit-scrollbar-thumb {
            background: #e50914;
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #ff6b6b;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold" href="{{ route('series-infos.index') }}">
                📺 CinéHub
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="navbar-nav ms-auto">
                    <a class="nav-link" href="{{ route('series-infos.index') }}">📺 مكتبتي</a>
                    <a class="nav-link active" href="{{ route('scraper.index') }}">🔍 اكتشف</a>
                    <form method="POST" action="{{ route('logout') }}" class="nav-item">
                        @csrf
                        <button type="submit" class="nav-link btn btn-link">🚪 خروج</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <!-- Header -->
    <header>
        <h1>🎬 جميع المسلسلات</h1>
        <p>اكتشف أفضل مسلسلات الدراما والإثارة</p>
    </header>

    <!-- Messages -->
    <div id="errorAlert" class="alert alert-danger alert-dismissible fade d-none" role="alert">
        <i class="fas fa-exclamation-circle"></i> <span id="errorText"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <div id="successAlert" class="alert alert-success alert-dismissible fade d-none" role="alert">
        <i class="fas fa-check-circle"></i> <span id="successText"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <!-- Controls -->
    <div class="controls">
        <button id="scrapeBtn" class="btn-action" onclick="app.scrape()">
            <i class="fas fa-rocket"></i> تحديث المسلسلات
        </button>
        <button id="clearBtn" class="btn-action btn-clear" onclick="app.clearCache()">
            <i class="fas fa-trash-alt"></i> مسح الذاكرة
        </button>
        <a href="{{ route('series-infos.index') }}" class="btn-action" style="text-decoration: none;">
            <i class="fas fa-heart"></i> مفضلاتي
        </a>
    </div>

    <div class="cache-info" id="cacheInfo"></div>

    <!-- Loading -->
    <div class="loading-container" id="loadingContainer">
        <div class="spinner"></div>
        <div class="progress-container">
            <div class="progress" role="progressbar">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="progressFill" style="width: 0%"></div>
            </div>
            <div class="progress-text">
                <span id="progressText">جاري تحميل المسلسلات...</span>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat">
            <div class="stat-number" id="totalCount">0</div>
            <div class="stat-label">مسلسل متاح</div>
        </div>
    </div>

    <!-- Grid -->
    <div id="gridContainer"></div>

    <!-- Empty State -->
    <div class="empty-state" id="emptyState" style="display: none;">
        <div class="empty-state-icon">🍿</div>
        <h2>لا توجد مسلسلات بعد</h2>
        <p>اضغط على زر "تحديث المسلسلات" لاكتشاف المحتوى الجديد</p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const app = {
            series: @json($initialSeries),
            isScraping: false,

            init() {
                this.updateUI();
                this.updateCacheInfo();
                console.log('✅ Scraper initialisé');
            },

            async scrape() {
                if (this.isScraping) return;

                this.isScraping = true;
                document.getElementById('scrapeBtn').disabled = true;
                this.showLoading(true);
                this.hideMessages();

                try {
                    document.getElementById('progressText').textContent = 'Envoi de la requête...';
                    document.getElementById('progressFill').style.width = '10%';

                    const response = await fetch('{{ route("api.scraper.scrape") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.message || data.error || 'Erreur du serveur');
                    }

                    document.getElementById('progressFill').style.width = '100%';
                    this.series = data.series;
                    this.updateUI();
                    this.updateCacheInfo();

                    const source = data.source === 'cache' ? '(depuis le cache)' : '';
                    this.showSuccess(`✅ ${data.total} séries trouvées ${source}`);

                } catch (error) {
                    console.error('Erreur:', error);
                    this.showError(`❌ ${error.message}`);
                } finally {
                    this.isScraping = false;
                    this.showLoading(false);
                    document.getElementById('scrapeBtn').disabled = false;
                }
            },

            async clearCache() {
                if (!confirm('Êtes-vous sûr de vouloir vider le cache?')) {
                    return;
                }

                try {
                    const response = await fetch('{{ route("api.scraper.clear-cache") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.series = [];
                        this.updateUI();
                        this.updateCacheInfo();
                        this.showSuccess('✅ Cache supprimé');
                    }
                } catch (error) {
                    this.showError(`❌ ${error.message}`);
                }
            },

            updateUI() {
                const container = document.getElementById('gridContainer');
                const emptyState = document.getElementById('emptyState');

                document.getElementById('totalCount').textContent = this.series.length;

                if (this.series.length === 0) {
                    container.innerHTML = '';
                    emptyState.style.display = 'block';
                    return;
                }

                emptyState.style.display = 'none';
                container.className = 'grid';
                container.innerHTML = this.series.map((serie, index) => `
                    <div class="serie-card" style="animation-delay: ${index * 0.05}s;">
                        <div class="serie-image-wrapper">
                            <img src="${this.escapeHtml(serie.image)}" alt="${this.escapeHtml(serie.titre)}"
                                 class="serie-image"
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 400 600%22%3E%3Crect fill=%22%232a2a2a%22 width=%22400%22 height=%22600%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-size=%2224%22 fill=%22%23666%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                            <div class="serie-overlay">
                                <div class="serie-title-overlay">${this.escapeHtml(serie.titre)}</div>
                                <div class="serie-actions">
                                    <button class="action-btn" title="Regarder" data-url="${this.escapeHtml(serie.url)}" onclick="app.openSerie(this.dataset.url);">
                                        <i class="fas fa-play"></i>
                                    </button>
                                    <button class="action-btn" title="Ajouter à ma collection" data-url="${this.escapeHtml(serie.url)}" data-titre="${this.escapeHtml(serie.titre)}" onclick="app.addSerieToCollection(this);">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                    <button class="action-btn" title="Plus d'infos" data-titre="${this.escapeHtml(serie.titre)}" onclick="app.showInfo(this.dataset.titre);">
                                        <i class="fas fa-info-circle"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="serie-title">${this.escapeHtml(serie.titre)}</div>
                    </div>
                `).join('');
            },

            updateCacheInfo() {
                const info = document.getElementById('cacheInfo');
                @if($isCached && $cacheExpiresAt)
                    info.textContent = `📦 Cached • Expires: {{ $cacheExpiresAt->format('H:i') }}`;
                @else
                    info.textContent = '';
                @endif
            },

            showLoading(show) {
                const container = document.getElementById('loadingContainer');
                if (show) {
                    container.classList.add('active');
                } else {
                    container.classList.remove('active');
                }
            },

            showError(message) {
                const elem = document.getElementById('errorAlert');
                document.getElementById('errorText').textContent = message;
                elem.classList.remove('d-none');
            },

            showSuccess(message) {
                const elem = document.getElementById('successAlert');
                document.getElementById('successText').textContent = message;
                elem.classList.remove('d-none');
                setTimeout(() => elem.classList.add('d-none'), 3000);
            },

            hideMessages() {
                document.getElementById('errorAlert').classList.add('d-none');
                document.getElementById('successAlert').classList.add('d-none');
            },

            escapeHtml(text) {
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, m => map[m]);
            },

            /**
             * Ouvrir la série dans une nouvelle fenêtre
             */
            openSerie(url) {
                window.open(url, '_blank');
            },

            /**
             * Afficher les infos de la série
             */
            showInfo(titre) {
                this.showSuccess(`📺 ${titre}`);
            },

            /**
             * Ajouter la série à la collection (déclencher le scraping automatique)
             */
            async addSerieToCollection(button) {
                const url = button.dataset.url;
                const titre = button.dataset.titre;

                button.disabled = true;

                try {
                    this.showSuccess(`⏳ Ajout de "${titre}" en cours...`);

                    // Préparer le formulaire pour le scraping
                    const formData = new FormData();
                    formData.append('list_page_url', url);

                    // Appeler directement le endpoint scrape avec preview d'abord
                    const previewResponse = await fetch('{{ route("series-infos.scrape-preview") }}', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: formData
                    });

                    const previewData = await previewResponse.json();

                    if (!previewResponse.ok) {
                        throw new Error(previewData.message || previewData.error || 'Erreur lors de la vérification des épisodes');
                    }

                    // Si le preview est ok, lancer le scraping automatiquement
                    const scrapeFormData = new FormData();
                    scrapeFormData.append('list_page_url', url);

                    // Ajouter les paramètres d'épisodes si disponibles
                    if (previewData.episodeMin && previewData.episodeMax) {
                        scrapeFormData.append('episode_start', previewData.episodeMin);
                        scrapeFormData.append('episode_end', previewData.episodeMax);
                    }

                    const scrapeResponse = await fetch('{{ route("series-infos.scrape") }}', {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: scrapeFormData
                    });

                    const scrapeData = await scrapeResponse.json();

                    if (!scrapeResponse.ok) {
                        throw new Error(scrapeData.message || 'Erreur lors du scraping');
                    }

                    // Afficher un message de succès avec le tracking key
                    const trackingKey = scrapeData.trackingKey;
                    this.showSuccess(`✅ "${titre}" ajoutée! Scraping en cours (ID: ${trackingKey.substring(0, 8)}...)`);

                    // Rediriger vers series-infos après 2 secondes
                    setTimeout(() => {
                        window.location.href = '{{ route("series-infos.index") }}';
                    }, 2000);

                } catch (error) {
                    console.error('Erreur lors de l\'ajout de la série:', error);
                    this.showError(`❌ Erreur: ${error.message}`);
                    button.disabled = false;
                }
            }
        };

        document.addEventListener('DOMContentLoaded', () => app.init());
    </script>
</body>
</html>
