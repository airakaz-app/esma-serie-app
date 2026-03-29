<template>
    <div class="scraper-container">
        <!-- Navigation Bar -->
        <nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
            <div class="container-fluid px-4">
                <a class="navbar-brand fw-bold" href="/series-infos">
                    📺 Séries App
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="navbarNav">
                    <div class="navbar-nav ms-auto">
                        <a class="nav-link" href="/series-infos">Mes Séries</a>
                        <a class="nav-link active" href="/scraper">Scraper</a>
                        <button @click="logout" class="nav-link btn btn-link">Déconnexion</button>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container py-5">
            <!-- Header -->
            <header>
                <h1>جميع المسلسلات</h1>
                <p>تجميع شامل لجميع المسلسلات المتاحة</p>
            </header>

            <!-- Messages -->
            <transition name="fade">
                <div v-if="errorMessage" class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ errorMessage }}
                    <button type="button" class="btn-close" @click="errorMessage = ''"></button>
                </div>
            </transition>

            <transition name="fade">
                <div v-if="successMessage" class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ successMessage }}
                    <button type="button" class="btn-close" @click="successMessage = ''"></button>
                </div>
            </transition>

            <!-- Contrôles -->
            <div class="controls">
                <button
                    @click="startScraping"
                    :disabled="isScraping"
                    class="btn-scraper"
                >
                    🚀 بدء الحصول على البيانات
                </button>
                <button
                    @click="clearCache"
                    class="btn-scraper btn-clear"
                    :disabled="isScraping"
                >
                    🗑️ مسح الذاكرة المؤقتة
                </button>
                <a href="/series-infos" class="btn-scraper" style="text-decoration: none; display: inline-block;">
                    📺 عرض مسلسلاتي
                </a>
            </div>

            <div v-if="cacheInfo" class="cache-info">
                {{ cacheInfo }}
            </div>

            <!-- Loading -->
            <transition name="fade">
                <div v-if="isScraping" class="loading-container">
                    <div class="spinner"></div>
                    <div class="progress-container">
                        <div class="progress" role="progressbar">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" :style="{width: progressPercent + '%'}"></div>
                        </div>
                        <div class="progress-text">
                            {{ progressText }}
                        </div>
                    </div>
                </div>
            </transition>

            <!-- Stats -->
            <div class="stats">
                <div class="stat">
                    <span class="stat-number">{{ allSeries.length }}</span>
                    <span class="stat-label">إجمالي المسلسلات</span>
                </div>
                <div class="stat">
                    <span class="stat-number">{{ Math.ceil(allSeries.length / 50) }}</span>
                    <span class="stat-label">عدد الصفحات</span>
                </div>
            </div>

            <!-- Grid -->
            <div v-if="allSeries.length > 0" class="grid">
                <a v-for="serie in allSeries"
                   :key="serie.url"
                   :href="serie.url"
                   target="_blank"
                   rel="noopener noreferrer"
                   class="serie-card">
                    <img :src="serie.image"
                         :alt="serie.titre"
                         class="serie-image"
                         @error="serie.image = placeholderImage">
                    <div class="serie-title">{{ serie.titre }}</div>
                </a>
            </div>

            <!-- Empty State -->
            <div v-else class="empty-state">
                <div class="empty-state-icon">📭</div>
                <h2>لا توجد بيانات</h2>
                <p>اضغط على الزر أعلاه لبدء الحصول على البيانات</p>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: 'SeriesScraper',
    data() {
        return {
            allSeries: [],
            isScraping: false,
            progressText: 'جاري التحميل...',
            progressPercent: 0,
            errorMessage: '',
            successMessage: '',
            cacheInfo: '',
            CORS_PROXY: 'https://corsproxy.io/?url=',
            BASE_URL: 'https://n.esheaq.onl',
            STORAGE_KEY: 'series_data_scraper',
            CACHE_DURATION: 24 * 60 * 60 * 1000,
            INITIAL_DATA: [
                { "titre": "مسلسل ميرا: كأن كل شيء على ما يرام مترجم", "url": "https://n.esheaq.onl/series/4dqmdplyoy/", "image": "https://n.esheaq.onl/wp-content/uploads/2026/03/1f0e4349-5b7b-45af-9f07-3009e108a583.webp" },
                { "titre": "مسلسل الياسمين مترجم", "url": "https://n.esheaq.onl/series/p59ltatvwc/", "image": "https://n.esheaq.onl/wp-content/uploads/2026/03/MV5BZmMwNzlhNmYtZGFlMy00NGE4LTk5MDMtNGZiMDc2ZGEwMTRkXkEyXkFqcGc@._V1_-scaled.jpg" },
                { "titre": "مسلسل لن يحدث لنا شيء مترجم", "url": "https://n.esheaq.onl/series/cbq03orvb1/", "image": "https://n.esheaq.onl/wp-content/uploads/2026/03/MV5BOGNhNDRhMmItMDJmNi00ZmJhLThkYzAtYjEwMzdjY2FiOTkxXkEyXkFqcGc@._V1_.jpg" },
                { "titre": "مسلسل الشجاع مترجمة", "url": "https://n.esheaq.onl/series/0iffaq38fn/", "image": "https://n.esheaq.onl/wp-content/uploads/2026/03/Shab-tmp-large.jpg" },
                { "titre": "مسلسل القبيحة مترجمة", "url": "https://n.esheaq.onl/series/7hxma8nii9/", "image": "https://n.esheaq.onl/wp-content/uploads/2026/03/القبيحة-موقع-قرمزي.webp" }
            ],
            placeholderImage: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22300%22%3E%3Crect fill=%22%23ddd%22 width=%22200%22 height=%22300%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 dy=%22.3em%22 font-size=%2214%22 fill=%22%23999%22%3EImage non trouvée%3C/text%3E%3C/svg%3E'
        };
    },

    mounted() {
        this.loadFromCache();
        this.updateCacheInfo();
    },

    methods: {
        loadFromCache() {
            try {
                const cached = localStorage.getItem(this.STORAGE_KEY);
                if (cached) {
                    const data = JSON.parse(cached);
                    if (this.isDataFresh(data.timestamp)) {
                        this.allSeries = data.series;
                        this.showSuccess('✅ Données chargées depuis le cache');
                        return true;
                    }
                }
            } catch (error) {
                console.error('Erreur cache:', error);
            }
            return false;
        },

        isDataFresh(timestamp) {
            return Date.now() - timestamp < this.CACHE_DURATION;
        },

        saveToCache(series) {
            try {
                const data = {
                    series: series,
                    timestamp: Date.now()
                };
                localStorage.setItem(this.STORAGE_KEY, JSON.stringify(data));
            } catch (error) {
                console.error('Erreur sauvegarde:', error);
            }
        },

        clearCache() {
            localStorage.removeItem(this.STORAGE_KEY);
            this.allSeries = [];
            this.updateCacheInfo();
            this.showSuccess('✅ Cache supprimé');
        },

        updateCacheInfo() {
            try {
                const cached = localStorage.getItem(this.STORAGE_KEY);
                if (cached) {
                    const data = JSON.parse(cached);
                    this.cacheInfo = `📦 Dernière mise à jour: ${this.formatTimeAgo(data.timestamp)}`;
                } else {
                    this.cacheInfo = '';
                }
            } catch (error) {
                this.cacheInfo = '';
            }
        },

        formatTimeAgo(timestamp) {
            const now = Date.now();
            const diff = now - timestamp;
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(diff / 3600000);
            const days = Math.floor(diff / 86400000);

            if (minutes < 1) return 'à l\'instant';
            if (minutes < 60) return `il y a ${minutes} minute(s)`;
            if (hours < 24) return `il y a ${hours} heure(s)`;
            return `il y a ${days} jour(s)`;
        },

        async startScraping() {
            if (this.isScraping) return;

            this.isScraping = true;
            this.allSeries = [...this.INITIAL_DATA];
            this.errorMessage = '';
            this.successMessage = '';

            try {
                this.updateProgress('Recherche de la page des séries...', 0);
                const pageUrl = await this.findAllSeriesPage();

                if (!pageUrl) throw new Error('Page non trouvée');

                this.updateProgress('Début du scraping...', 10);
                await this.scrapeAllPages(pageUrl);

                this.saveToCache(this.allSeries);
                this.showSuccess(`✅ Succès! ${this.allSeries.length} séries trouvées`);

            } catch (error) {
                this.showError(`❌ Erreur: ${error.message}`);
            } finally {
                this.isScraping = false;
                this.updateCacheInfo();
            }
        },

        async findAllSeriesPage() {
            const response = await this.fetchWithCORS(this.BASE_URL);
            const parser = new DOMParser();
            const doc = parser.parseFromString(response, 'text/html');

            const links = doc.querySelectorAll('nav a, .menu a, .nav a');
            for (const link of links) {
                if (link.textContent.includes('جميع المسلسلات')) {
                    const href = link.getAttribute('href');
                    if (href) return href;
                }
            }

            throw new Error('Lien "جميع المسلسلات" non trouvé');
        },

        async scrapeAllPages(startUrl) {
            let currentUrl = startUrl;
            let pageCount = 0;

            while (currentUrl && pageCount < 50) {
                pageCount++;
                this.updateProgress(`Scraping page ${pageCount}...`, 10 + (pageCount * 5));

                const response = await this.fetchWithCORS(currentUrl);
                const parser = new DOMParser();
                const doc = parser.parseFromString(response, 'text/html');

                const articles = doc.querySelectorAll('article');

                for (const article of articles) {
                    const link = article.querySelector('a');
                    const image = article.querySelector('img');

                    if (link && image) {
                        const titre = link.getAttribute('title') || link.textContent.trim();
                        const url = link.getAttribute('href');
                        const imageUrl = image.getAttribute('src') || image.getAttribute('data-src');

                        if (titre && url && imageUrl) {
                            if (!this.allSeries.some(s => s.url === url)) {
                                this.allSeries.push({ titre: titre.trim(), url, image: imageUrl });
                            }
                        }
                    }
                }

                currentUrl = this.findNextPageLink(doc);
                if (!currentUrl) break;

                await this.sleep(500);
            }
        },

        findNextPageLink(doc) {
            const pagination = doc.querySelector('.pagination, .nav-pagination, .paging');
            if (!pagination) return null;

            const links = pagination.querySelectorAll('a');
            for (const link of links) {
                const text = link.textContent.trim();
                if (text === 'التالي' || text === '>') {
                    return link.getAttribute('href');
                }
            }
            return null;
        },

        async fetchWithCORS(url) {
            const corsUrl = this.CORS_PROXY + encodeURIComponent(url);
            const response = await fetch(corsUrl);
            if (!response.ok) throw new Error(`Erreur HTTP: ${response.status}`);
            return await response.text();
        },

        sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        },

        updateProgress(text, percent) {
            this.progressText = text;
            this.progressPercent = Math.min(percent, 95);
        },

        showError(message) {
            this.errorMessage = message;
        },

        showSuccess(message) {
            this.successMessage = message;
            setTimeout(() => this.successMessage = '', 3000);
        },

        logout() {
            document.querySelector('form[action*="logout"]').submit();
        }
    }
};
</script>

<style scoped>
.scraper-container {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}

.navbar-custom {
    background: rgba(0, 0, 0, 0.1);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

header {
    color: white;
    text-align: center;
    margin-bottom: 40px;
    animation: fadeInDown 0.6s ease-out;
}

header h1 {
    font-size: 3em;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
    margin-bottom: 10px;
}

.controls {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

.btn-scraper {
    padding: 12px 24px;
    border-radius: 8px;
    background: white;
    color: #667eea;
    border: none;
    font-weight: bold;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    cursor: pointer;
}

.btn-scraper:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
}

.btn-scraper:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.btn-clear {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 2px solid white;
}

.btn-clear:hover {
    background: white;
    color: #667eea;
}

.stats {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-bottom: 30px;
    color: white;
    flex-wrap: wrap;
}

.stat {
    text-align: center;
}

.stat-number {
    font-size: 2.5em;
    font-weight: bold;
    display: block;
}

.stat-label {
    font-size: 0.9em;
    opacity: 0.9;
}

.loading-container {
    text-align: center;
    margin-bottom: 30px;
}

.spinner {
    border: 4px solid rgba(255, 255, 255, 0.3);
    border-top: 4px solid white;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

@media (max-width: 768px) {
    .grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 480px) {
    .grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

.serie-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    animation: fadeInUp 0.6s ease-out;
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
}

.serie-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
}

.serie-image {
    width: 100%;
    aspect-ratio: 2/3;
    object-fit: cover;
    background: #e0e0e0;
}

.serie-title {
    padding: 12px;
    text-align: center;
    font-size: 0.95em;
    color: #333;
    line-height: 1.4;
    font-weight: 500;
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.cache-info {
    text-align: center;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.9em;
    margin-top: 10px;
}

.empty-state {
    text-align: center;
    color: white;
    padding: 40px 20px;
}

.empty-state-icon {
    font-size: 4em;
    margin-bottom: 20px;
}

@keyframes fadeInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fade-enter-active, .fade-leave-active {
    transition: opacity 0.3s ease;
}

.fade-enter-from, .fade-leave-to {
    opacity: 0;
}
</style>
